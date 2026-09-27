<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Services\GroupSavingsJournalService;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanRepaymentServiceInterface;
use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\GuarantorRecovery;
use App\Tenant\Modules\Loans\Models\GuarantorRecoveryLine;
use App\Tenant\Modules\Loans\Models\GuarantorRecoveryRepayment;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Services\GroupSavingsBalanceService;
use App\Tenant\Modules\Savings\Services\SavingsTransactionPostingService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Support\TenantMoney;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Recovers a defaulted loan from savings: the borrower's own free savings first,
 * then its guarantors', shared in proportion to what each guaranteed and capped at
 * both their guarantee and the savings they have free. Each debit is an ordinary
 * repayment from savings, so the loan's schedule and the ledger are updated the way
 * they are for any other repayment from savings.
 *
 * A recovery is proposed by one staff member and approved, which executes it, by
 * another. What the guarantors paid then becomes a recovery loan the borrower owes
 * them, in equal monthly instalments; each repayment is paid into the guarantors'
 * savings in proportion to what they are still owed.
 *
 * A group guarantor's share comes out of the group's savings accounts, and out of
 * its members in proportion to what each has in the group; the borrower's
 * repayments go back to the same members. Written-off loans are left out: the loan
 * is already off the books, and recovering it is bad-debt recovery, not a repayment.
 */
class GuarantorRecoveryService
{
    /** Loan statuses that still accept repayments from savings. */
    private const RECOVERABLE_LOAN_STATUSES = [LoanStatus::Disbursed, LoanStatus::Arrears, LoanStatus::Rescheduled];

    public function __construct(
        protected LoanGuarantorServiceInterface $guarantors,
        protected LoanRepaymentServiceInterface $repayments,
        protected SavingsTransactionPostingService $posting,
        protected GuarantorArrearsService $arrears,
        protected GuarantorNotifier $notifier,
        protected GroupSavingsBalanceService $groupBalances,
        protected GroupSavingsJournalService $groupJournal,
    ) {}

    /**
     * Who would pay what to recover $amount (default: the whole outstanding balance)
     * of the loan, and whether it can be recovered now.
     */
    public function plan(Loan $loan, ?float $amount = null): array
    {
        $rules = GuarantorRules::for();
        $outstanding = round((float) $loan->outstanding_balance, 2);
        $requested = round(min($amount ?? $outstanding, $outstanding), 2);
        $overdue = $this->arrears->overdueLoans($loan->id)->get($loan->id);

        $reasons = [];
        if ($loan->status === LoanStatus::WrittenOff) {
            $reasons[] = 'This loan has been written off, so it is already off the books; recovering it is bad-debt recovery, which is not handled here.';
        } elseif (! in_array($loan->status, self::RECOVERABLE_LOAN_STATUSES, true)) {
            $reasons[] = 'Only a disbursed loan that is still open can be recovered.';
        }
        if (! $overdue) {
            $reasons[] = 'This loan is not overdue, or has no guarantees standing behind it.';
        } elseif ($overdue['days_past_due'] < $rules->recoveryAfterDays) {
            $reasons[] = "A loan must be {$rules->recoveryAfterDays} days overdue before it is recovered from guarantors; this one is {$overdue['days_past_due']}.";
        }
        if (GuarantorRecovery::where('loan_id', $loan->id)->where('status', GuarantorRecovery::STATUS_PENDING_APPROVAL)->exists()) {
            $reasons[] = 'A recovery of this loan is already waiting for approval.';
        }
        if ($requested <= 0) {
            $reasons[] = 'Enter an amount to recover.';
        }

        $lines = collect();
        $remaining = max(0, $requested);

        // 1. The borrower's own savings, less what they hold for loans they guarantee.
        $borrowerLines = $this->debitsFrom((int) $loan->member_id, $remaining, $loan->id);
        foreach ($borrowerLines as $line) {
            $lines->push([...$line, 'source' => GuarantorRecoveryLine::SOURCE_BORROWER, 'loan_application_guarantor_id' => null]);
        }
        $remaining = round($remaining - $borrowerLines->sum('amount'), 2);

        // 2. Guarantors, in proportion to what each guaranteed.
        $pledges = LoanApplicationGuarantor::query()
            ->where('loan_id', $loan->id)
            ->where('status', LoanApplicationGuarantor::STATUS_LOCKED)
            ->with(['member', 'group'])
            ->get();

        $caps = $pledges->mapWithKeys(function (LoanApplicationGuarantor $pledge) use ($loan, $lines) {
            $free = $pledge->guarantor_type === LoanApplicationGuarantor::TYPE_GROUP
                ? $this->freeGroupSavings((int) $pledge->guarantor_id, $loan->id) - $lines->where('savings_group_id', $pledge->guarantor_id)->sum('amount')
                : $this->freeSavings((int) $pledge->guarantor_id, $loan->id) - $lines->where('member_id', $pledge->guarantor_id)->sum('amount');
            $left = (float) $pledge->guarantee_amount - (float) $pledge->recovered_amount;

            return [$pledge->id => round(max(0, min($left, $free)), 2)];
        })->all();

        $shares = $this->shareProRata(
            $remaining,
            $pledges->mapWithKeys(fn ($p) => [$p->id => (float) $p->guarantee_amount])->all(),
            $caps
        );

        foreach ($pledges as $pledge) {
            $share = $shares[$pledge->id] ?? 0.0;
            $debits = $pledge->guarantor_type === LoanApplicationGuarantor::TYPE_GROUP
                ? $this->debitsFromGroup((int) $pledge->guarantor_id, $share, $loan->id, $pledge->guarantor_account_id, $lines)
                : $this->debitsFrom((int) $pledge->guarantor_id, $share, $loan->id, $lines);

            foreach ($debits as $line) {
                $lines->push([...$line, 'source' => GuarantorRecoveryLine::SOURCE_GUARANTOR, 'loan_application_guarantor_id' => $pledge->id]);
            }
        }

        $borrowerTotal = round($lines->where('source', GuarantorRecoveryLine::SOURCE_BORROWER)->sum('amount'), 2);
        $guarantorTotal = round($lines->where('source', GuarantorRecoveryLine::SOURCE_GUARANTOR)->sum('amount'), 2);
        $planned = round($borrowerTotal + $guarantorTotal, 2);

        if (! $reasons && $planned <= 0) {
            $reasons[] = 'Neither the borrower nor any guarantor has savings free to recover from.';
        }

        $names = Member::whereIn('id', $lines->pluck('member_id')->filter()->unique())->pluck('name', 'id');
        $accountNos = SavingsAccount::whereIn('id', $lines->pluck('savings_account_id')->filter())->pluck('account_no', 'id');
        $groupNames = SavingsGroup::whereIn('id', $lines->pluck('savings_group_id')->filter()->unique())->pluck('name', 'id');
        $groupAccountNos = DB::connection('tenant')->table('group_savings_accounts')
            ->whereIn('id', $lines->pluck('group_savings_account_id')->filter())->pluck('code', 'id');

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
            'loan_id' => $loan->id,
            'loan_no' => $loan->loan_no,
            'days_past_due' => $overdue['days_past_due'] ?? 0,
            'outstanding_balance' => $outstanding,
            'requested_amount' => $requested,
            'borrower_amount' => $borrowerTotal,
            'guarantor_amount' => $guarantorTotal,
            'planned_amount' => $planned,
            'shortfall' => round(max(0, $requested - $planned), 2),
            'recovery_loan_term_months' => $rules->recoveryLoanTermMonths,
            'lines' => $lines->map(fn ($line) => [
                ...$line,
                'name' => $line['savings_group_id'] ? ($groupNames[$line['savings_group_id']] ?? null) : ($names[$line['member_id']] ?? null),
                'account_no' => $line['group_savings_account_id']
                    ? ($groupAccountNos[$line['group_savings_account_id']] ?? null)
                    : ($accountNos[$line['savings_account_id']] ?? null),
            ])->values()->all(),
        ];
    }

    /** @throws ValidationException */
    public function propose(Loan $loan, ?float $amount, ?string $notes, int $actorId): GuarantorRecovery
    {
        return DB::connection('tenant')->transaction(function () use ($loan, $amount, $notes, $actorId) {
            Loan::whereKey($loan->id)->lockForUpdate()->first();
            $plan = $this->plan($loan->fresh(), $amount);

            if (! $plan['eligible']) {
                throw ValidationException::withMessages(['recovery' => $plan['reasons']]);
            }

            $recovery = GuarantorRecovery::create([
                'loan_id' => $loan->id,
                'member_id' => $loan->member_id,
                'status' => GuarantorRecovery::STATUS_PENDING_APPROVAL,
                'requested_amount' => $plan['requested_amount'],
                'borrower_amount' => $plan['borrower_amount'],
                'guarantor_amount' => $plan['guarantor_amount'],
                'notes' => $notes,
                'initiated_by' => $actorId,
                'initiated_at' => now(),
            ]);
            $recovery->update(['code' => sprintf('GRC%06d', $recovery->id)]);

            foreach ($plan['lines'] as $line) {
                $recovery->lines()->create([
                    'source' => $line['source'],
                    'loan_application_guarantor_id' => $line['loan_application_guarantor_id'],
                    'member_id' => $line['member_id'],
                    'savings_account_id' => $line['savings_account_id'],
                    'group_savings_account_id' => $line['group_savings_account_id'],
                    'amount' => $line['amount'],
                ]);
            }

            return $recovery->load('lines');
        });
    }

    /**
     * Approve and execute a proposed recovery exactly as proposed. Refused when the
     * approver proposed it, or when savings or the loan have changed so that it can
     * no longer be carried out as proposed.
     *
     * @throws ValidationException
     */
    public function approve(GuarantorRecovery $recovery, int $actorId): GuarantorRecovery
    {
        $this->assertPending($recovery);

        if ((int) $recovery->initiated_by === $actorId) {
            throw ValidationException::withMessages([
                'recovery' => ['A recovery must be approved by someone other than the person who proposed it.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($recovery, $actorId) {
            $loan = Loan::whereKey($recovery->loan_id)->lockForUpdate()->firstOrFail();
            $recovery->load('lines.savingsAccount');
            $this->assertStillExecutable($recovery, $loan);

            $today = now()->toDateString();

            foreach ($recovery->lines as $line) {
                $data = [
                    'amount' => (float) $line->amount,
                    'payment_date' => $today,
                    'narration' => $line->source === GuarantorRecoveryLine::SOURCE_GUARANTOR
                        ? "Guarantee recovery {$recovery->code} – loan {$loan->loan_no}"
                        : "Recovery {$recovery->code} from own savings – loan {$loan->loan_no}",
                    'notes' => $recovery->code,
                ];

                if ($line->isGroup()) {
                    $groupId = (int) DB::connection('tenant')->table('group_savings_accounts')->where('id', $line->group_savings_account_id)->value('savings_group_id');
                    $split = $this->groupBalances->memberSplit($groupId, (float) $line->amount);
                    $txn = $this->repayments->repayFromGroupSavings($loan->fresh(), [
                        ...$data,
                        'group_savings_account_id' => $line->group_savings_account_id,
                        'member_split' => $split,
                    ], $actorId);
                    $line->update(['loan_transaction_id' => $txn->id, 'group_member_split' => $split]);

                    continue;
                }

                $txn = $this->repayments->repayFromSavings($loan->fresh(), [
                    ...$data,
                    'savings_account_id' => $line->savings_account_id,
                ], $actorId);

                $line->update(['loan_transaction_id' => $txn->id]);
            }

            $taken = $recovery->lines
                ->where('source', GuarantorRecoveryLine::SOURCE_GUARANTOR)
                ->groupBy('loan_application_guarantor_id')
                ->map(fn ($lines) => (float) $lines->sum('amount'));

            foreach ($taken as $pledgeId => $amount) {
                $pledge = LoanApplicationGuarantor::find($pledgeId);
                $pledge->forceFill([
                    'recovered_amount' => round((float) $pledge->recovered_amount + $amount, 2),
                    // Drawn on: the guarantee has been used, so it no longer holds savings.
                    'status' => LoanApplicationGuarantor::STATUS_INVOKED,
                    'status_changed_at' => now(),
                ])->save();
            }

            $rules = GuarantorRules::for();
            $hasRecoveryLoan = (float) $recovery->guarantor_amount > 0;

            $recovery->update([
                'status' => GuarantorRecovery::STATUS_EXECUTED,
                'approved_by' => $actorId,
                'approved_at' => now(),
                'executed_at' => now(),
                'recovery_loan_status' => $hasRecoveryLoan ? GuarantorRecovery::RECOVERY_LOAN_OPEN : null,
                'recovery_loan_term_months' => $hasRecoveryLoan ? $rules->recoveryLoanTermMonths : null,
                'recovery_loan_first_due_date' => $hasRecoveryLoan ? now()->addMonthNoOverflow()->toDateString() : null,
            ]);

            foreach ($taken as $pledgeId => $amount) {
                $this->notifier->recovered(LoanApplicationGuarantor::find($pledgeId), $amount, $loan->loan_no);
            }
            if ($hasRecoveryLoan) {
                $this->notifier->recoveryLoanCreated($recovery, $loan->loan_no, $this->schedule($recovery)[0]['amount'] ?? 0.0);
            }

            return $recovery->fresh(['lines']);
        });
    }

    /** @throws ValidationException */
    public function reject(GuarantorRecovery $recovery, string $reason, int $actorId): GuarantorRecovery
    {
        $this->assertPending($recovery);

        $recovery->update([
            'status' => GuarantorRecovery::STATUS_REJECTED,
            'rejected_by' => $actorId,
            'rejected_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $recovery;
    }

    /**
     * The recovery loan's instalments, and how far the borrower has paid them.
     *
     * @return list<array{installment_no: int, due_date: string, amount: float, paid: float, status: string}>
     */
    public function schedule(GuarantorRecovery $recovery): array
    {
        $total = (float) $recovery->guarantor_amount;
        $terms = (int) $recovery->recovery_loan_term_months;

        if ($total <= 0 || $terms <= 0 || ! $recovery->recovery_loan_first_due_date) {
            return [];
        }

        $each = floor($total / $terms * 100) / 100;
        $paidLeft = (float) $recovery->recovery_loan_repaid;
        $today = now()->startOfDay();
        $rows = [];

        for ($i = 1; $i <= $terms; $i++) {
            // The last instalment takes the rounding remainder.
            $amount = $i === $terms ? round($total - $each * ($terms - 1), 2) : $each;
            $due = Carbon::parse($recovery->recovery_loan_first_due_date)->addMonthsNoOverflow($i - 1);
            $paid = round(min($amount, $paidLeft), 2);
            $paidLeft = round($paidLeft - $paid, 2);

            $rows[] = [
                'installment_no' => $i,
                'due_date' => $due->toDateString(),
                'amount' => $amount,
                'paid' => $paid,
                'status' => $paid >= $amount ? 'paid' : ($due->lt($today) ? 'overdue' : ($paid > 0 ? 'partial' : 'pending')),
            ];
        }

        return $rows;
    }

    /**
     * Record the borrower's repayment of a recovery loan, paying it into the
     * guarantors' savings in proportion to what each is still owed.
     *
     * @param  array{payment_date?: string, payment_mode?: string, reference?: string}  $data
     *
     * @throws ValidationException
     */
    public function repay(GuarantorRecovery $recovery, float $amount, array $data, int $actorId): GuarantorRecoveryRepayment
    {
        if ($recovery->recovery_loan_status !== GuarantorRecovery::RECOVERY_LOAN_OPEN) {
            throw ValidationException::withMessages(['recovery' => ['This recovery loan is not open for repayment.']]);
        }

        $outstanding = $recovery->recoveryLoanOutstanding();
        $amount = round($amount, 2);

        if ($amount <= 0 || $amount > $outstanding) {
            throw ValidationException::withMessages([
                'amount' => ['Enter an amount up to the '.TenantMoney::format($outstanding).' still owed.'],
            ]);
        }

        return DB::connection('tenant')->transaction(function () use ($recovery, $amount, $data, $actorId) {
            $recovery = GuarantorRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            $lines = $recovery->lines()->where('source', GuarantorRecoveryLine::SOURCE_GUARANTOR)->with('savingsAccount')->get();
            $borrowerName = $recovery->member?->name;
            $date = $data['payment_date'] ?? now()->toDateString();

            $owed = $lines->mapWithKeys(fn (GuarantorRecoveryLine $l) => [$l->id => $l->owed()])->all();
            $shares = $this->shareProRata($amount, $owed, $owed);

            $allocations = [];
            foreach ($lines as $line) {
                $share = $shares[$line->id] ?? 0.0;
                if ($share <= 0) {
                    continue;
                }

                $narration = "Repayment by {$borrowerName} of recovery loan {$recovery->code}";
                $txn = $line->isGroup()
                    ? $this->payGroupBack($line, $share, $date, $data['payment_mode'] ?? 'cash', $narration, $recovery->code, $actorId)
                    : $this->posting->deposit($line->savingsAccount, [
                        'amount' => $share,
                        'deposit_date' => $date,
                        'payment_mode' => $data['payment_mode'] ?? 'cash',
                        'deposited_by' => $borrowerName,
                        'narration' => $narration,
                        'skip_charges' => true,
                    ], $actorId);

                $line->update(['repaid_amount' => round((float) $line->repaid_amount + $share, 2)]);
                $allocations[] = [
                    'line_id' => $line->id,
                    'member_id' => $line->member_id,
                    'group_savings_account_id' => $line->group_savings_account_id,
                    'amount' => $share,
                    'transaction_id' => $txn->id,
                ];
            }

            $repaid = round((float) $recovery->recovery_loan_repaid + $amount, 2);
            $recovery->update([
                'recovery_loan_repaid' => $repaid,
                'recovery_loan_status' => $repaid >= (float) $recovery->guarantor_amount
                    ? GuarantorRecovery::RECOVERY_LOAN_SETTLED
                    : GuarantorRecovery::RECOVERY_LOAN_OPEN,
            ]);

            return $recovery->repayments()->create([
                'amount' => $amount,
                'payment_date' => $date,
                'payment_mode' => $data['payment_mode'] ?? 'cash',
                'reference' => $data['reference'] ?? null,
                'allocations' => $allocations,
                'created_by' => $actorId,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function assertPending(GuarantorRecovery $recovery): void
    {
        if ($recovery->status !== GuarantorRecovery::STATUS_PENDING_APPROVAL) {
            throw ValidationException::withMessages(['recovery' => ['This recovery is no longer waiting for approval.']]);
        }
    }

    private function assertStillExecutable(GuarantorRecovery $recovery, Loan $loan): void
    {
        $problems = [];
        $total = (float) $recovery->lines->sum('amount');

        if (! in_array($loan->status, self::RECOVERABLE_LOAN_STATUSES, true)) {
            $problems[] = 'The loan is no longer open for repayment.';
        } elseif ($total > round((float) $loan->outstanding_balance, 2) + 0.001) {
            $problems[] = 'The loan now owes less than this recovery would take, so it would overpay.';
        }

        [$groupLines, $memberLines] = $recovery->lines->partition(fn (GuarantorRecoveryLine $l) => $l->isGroup());
        $groupAccounts = DB::connection('tenant')->table('group_savings_accounts')
            ->whereIn('id', $groupLines->pluck('group_savings_account_id'))
            ->whereNull('deleted_at')
            ->get(['id', 'balance', 'savings_group_id'])
            ->keyBy('id');

        foreach ($memberLines->groupBy('member_id') as $memberId => $lines) {
            $needed = (float) $lines->sum('amount');
            if ($needed > $this->freeSavings((int) $memberId, $loan->id) + 0.001) {
                $problems[] = 'A member\'s free savings have dropped since this recovery was proposed.';
                break;
            }
        }

        foreach ($groupLines->groupBy(fn ($l) => $groupAccounts[$l->group_savings_account_id]->savings_group_id ?? 0) as $groupId => $lines) {
            if (! $groupId || (float) $lines->sum('amount') > $this->freeGroupSavings((int) $groupId, $loan->id) + 0.001) {
                $problems[] = 'A group\'s free savings have dropped since this recovery was proposed.';
                break;
            }
        }

        foreach ($recovery->lines as $line) {
            $account = $line->isGroup() ? ($groupAccounts[$line->group_savings_account_id] ?? null) : $line->savingsAccount;
            if (! $account || (float) $account->balance < (float) $line->amount) {
                $problems[] = 'A savings account no longer holds what this recovery would take from it.';
                break;
            }
        }

        if ($problems) {
            $problems[] = 'Reject this recovery and propose it again.';
            throw ValidationException::withMessages(['recovery' => $problems]);
        }
    }

    /** A member's savings not held for loans other than $loanId. */
    private function freeSavings(int $memberId, int $loanId): float
    {
        $balance = (float) SavingsAccount::where('member_id', $memberId)
            ->where('status', 'active')
            ->sum('balance');

        return max(0, $balance - $this->guarantors->heldAmount('individual', $memberId, $loanId));
    }

    /**
     * Debits of up to $amount from a member's active savings accounts, largest
     * balance first, never taking more than their free savings.
     *
     * @param  Collection|null  $alreadyPlanned  earlier lines, so the same account is not overdrawn
     * @return Collection<int, array{member_id: int, savings_account_id: int, savings_group_id: null, group_savings_account_id: null, amount: float}>
     */
    private function debitsFrom(int $memberId, float $amount, int $loanId, ?Collection $alreadyPlanned = null): Collection
    {
        $alreadyPlanned ??= collect();
        $left = round(min($amount, $this->freeSavings($memberId, $loanId) - $alreadyPlanned->where('member_id', $memberId)->sum('amount')), 2);
        $debits = collect();

        if ($left <= 0) {
            return $debits;
        }

        $accounts = SavingsAccount::where('member_id', $memberId)
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->get();

        foreach ($accounts as $account) {
            $room = (float) $account->balance - $alreadyPlanned->where('savings_account_id', $account->id)->sum('amount');
            $take = round(min($left, $room), 2);
            if ($take <= 0) {
                continue;
            }

            $debits->push([
                'member_id' => $memberId,
                'savings_account_id' => $account->id,
                'savings_group_id' => null,
                'group_savings_account_id' => null,
                'amount' => $take,
            ]);
            $left = round($left - $take, 2);

            if ($left <= 0) {
                break;
            }
        }

        return $debits;
    }

    /** A group's savings not held for loans other than $loanId. */
    private function freeGroupSavings(int $groupId, int $loanId): float
    {
        $balance = (float) DB::connection('tenant')->table('group_savings_accounts')
            ->where('savings_group_id', $groupId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->sum('balance');

        return max(0, $balance - $this->guarantors->heldAmount(LoanApplicationGuarantor::TYPE_GROUP, $groupId, $loanId));
    }

    /**
     * Debits of up to $amount from a group's active savings accounts: the account it
     * pledged first, if it named one, then largest balance first.
     *
     * @return Collection<int, array{member_id: null, savings_account_id: null, savings_group_id: int, group_savings_account_id: int, amount: float}>
     */
    private function debitsFromGroup(int $groupId, float $amount, int $loanId, ?int $pledgedAccountId, Collection $alreadyPlanned): Collection
    {
        $left = round(min($amount, $this->freeGroupSavings($groupId, $loanId) - $alreadyPlanned->where('savings_group_id', $groupId)->sum('amount')), 2);
        $debits = collect();

        if ($left <= 0) {
            return $debits;
        }

        $accounts = DB::connection('tenant')->table('group_savings_accounts')
            ->where('savings_group_id', $groupId)
            ->whereNull('deleted_at')
            ->where('status', 'active')
            ->where('balance', '>', 0)
            ->get(['id', 'balance'])
            ->sortByDesc(fn ($a) => [(int) ((int) $a->id === $pledgedAccountId), (float) $a->balance]);

        foreach ($accounts as $account) {
            $room = (float) $account->balance - $alreadyPlanned->where('group_savings_account_id', $account->id)->sum('amount');
            $take = round(min($left, $room), 2);
            if ($take <= 0) {
                continue;
            }

            $debits->push([
                'member_id' => null,
                'savings_account_id' => null,
                'savings_group_id' => $groupId,
                'group_savings_account_id' => (int) $account->id,
                'amount' => $take,
            ]);
            $left = round($left - $take, 2);

            if ($left <= 0) {
                break;
            }
        }

        return $debits;
    }

    /**
     * Pay part of a recovery loan back into a group's savings account, and back to
     * the members it was taken from, in the proportion it was taken from them.
     */
    private function payGroupBack(GuarantorRecoveryLine $line, float $amount, string $date, string $paymentMode, string $narration, ?string $reference, int $actorId): Transaction
    {
        $taken = array_map('floatval', $line->group_member_split ?? []);
        $split = $this->groupBalances->spread($amount, $taken, $taken);

        $txn = $this->groupBalances->credit((int) $line->group_savings_account_id, $amount, $split, $narration, $date, $paymentMode, $actorId);
        $this->groupJournal->postDeposit((int) $line->group_savings_account_id, $amount, 0, null, $paymentMode, $narration, $reference, $date, $actorId);

        return $txn;
    }

    /**
     * Split $total across keys in proportion to $weights, never giving a key more
     * than its cap; what a capped key cannot take is shared among the rest.
     *
     * @param  array<int, float>  $weights
     * @param  array<int, float>  $caps
     * @return array<int, float>
     */
    private function shareProRata(float $total, array $weights, array $caps): array
    {
        $shares = array_fill_keys(array_keys($weights), 0.0);
        $left = round($total, 2);

        while ($left > 0.004) {
            $open = array_filter(array_keys($weights), fn ($k) => $caps[$k] - $shares[$k] > 0.004 && $weights[$k] > 0);
            if (! $open) {
                break;
            }

            $weightSum = array_sum(array_map(fn ($k) => $weights[$k], $open));
            $given = 0.0;

            foreach ($open as $k) {
                $portion = floor($left * $weights[$k] / $weightSum * 100) / 100;
                $portion = min($portion, round($caps[$k] - $shares[$k], 2));
                $shares[$k] = round($shares[$k] + $portion, 2);
                $given += $portion;
            }

            // Cents lost to rounding go to whoever has room, one cent at a time.
            if ($given <= 0) {
                foreach ($open as $k) {
                    if ($left - $given < 0.005) {
                        break;
                    }
                    $shares[$k] = round($shares[$k] + 0.01, 2);
                    $given += 0.01;
                }
            }

            $left = round($left - $given, 2);
        }

        return $shares;
    }
}
