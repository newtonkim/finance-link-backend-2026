<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Support\BranchContext;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Members\Services\MemberChargeService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SavingsTransactionPostingService
{
    public function __construct(
        private readonly SavingsJournalService $savingsJournal,
        private readonly ChargeApplicationServiceInterface $chargeApplication,
    ) {}

    public function deposit(SavingsAccount $savingsAccount, array $data, ?int $actorId = null): Transaction
    {
        return DB::connection('tenant')->transaction(function () use ($savingsAccount, $data, $actorId) {
            $account = SavingsAccount::query()
                ->with('member')
                ->whereKey($savingsAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanTransact($account, 'deposit');

            $receiptNumber = $this->generateReceiptNumber();
            $transactionRef = $this->generateTransactionReference();
            $branchId = BranchContext::actingBranchId();
            $createdBy = $actorId ?? Auth::id();
            $amount = (float) $data['amount'];

            $account->balance += $amount;
            $account->loadMissing('savingsProduct.charges');

            foreach ($this->resolveSelectedCharges($account) as $charge) {
                if (($charge['type'] ?? '') !== 'deposit') {
                    continue;
                }

                if (! $this->chargeAppliesToAmount($charge, $amount)) {
                    continue;
                }

                $chargeAmount = $this->calculateChargeAmount($charge, $amount);
                if ($chargeAmount <= 0) {
                    continue;
                }

                $account->balance -= $chargeAmount;
                $chargeName = $charge['name'] ?? null;
                $chargeTxn = Transaction::create([
                    'reference' => $this->generateChargeReference(),
                    'receipt_number' => $receiptNumber,
                    'member_id' => $account->member_id,
                    'type' => 'charge',
                    'amount' => $chargeAmount,
                    'payment_mode' => $data['payment_mode'] ?? null,
                    'deposited_by' => 'System (Charge)',
                    'transaction_date' => $data['deposit_date'],
                    'account_id' => $account->id,
                    'account_type' => SavingsAccount::class,
                    'narration' => 'Deposit Charge'.($chargeName ? ': '.$chargeName : ''),
                    'charge_name' => $chargeName,
                    'is_reversible' => $charge['is_reversible'] ?? true,
                    'grouped_with' => $receiptNumber,
                    'created_by' => $createdBy,
                    'branch_id' => $branchId,
                ]);

                $this->savingsJournal->postCharge($chargeTxn, $account);
            }

            $account->save();

            $depositTxn = Transaction::create([
                'reference' => $transactionRef,
                'receipt_number' => $receiptNumber,
                'member_id' => $account->member_id,
                'type' => 'deposit',
                'amount' => $amount,
                'deposited_amount_before_charge' => $amount,
                'payment_mode' => $data['payment_mode'] ?? null,
                'deposited_by' => $data['deposited_by'] ?? null,
                'transaction_date' => $data['deposit_date'],
                'account_id' => $account->id,
                'account_type' => SavingsAccount::class,
                'narration' => $data['narration'] ?? null,
                'created_by' => $createdBy,
                'branch_id' => $branchId,
            ]);

            $this->savingsJournal->postDeposit($depositTxn, $account);

            $this->chargeApplication->applyForSavingsEvent(
                savingsAccountId: $account->id,
                eventType: 'deposit',
                transactionAmount: (float) $depositTxn->amount,
                transactionId: $depositTxn->id,
                actorId: $depositTxn->created_by ?? $createdBy,
            );

            if ($account->member) {
                app(MemberChargeService::class)->collectPendingCharges(
                    $account->member,
                    $account,
                    $this->savingsJournal,
                );
            }

            return $depositTxn;
        });
    }

    public function withdraw(SavingsAccount $savingsAccount, array $data, ?int $actorId = null): Transaction
    {
        return DB::connection('tenant')->transaction(function () use ($savingsAccount, $data, $actorId) {
            $account = SavingsAccount::query()
                ->with('member')
                ->whereKey($savingsAccount->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertCanTransact($account, 'withdraw');
            $this->assertCanWithdraw($account, (float) $data['amount']);

            $receiptNumber = $this->generateReceiptNumber();
            $transactionRef = $this->generateTransactionReference();
            $branchId = BranchContext::actingBranchId();
            $createdBy = $actorId ?? Auth::id();
            $amount = (float) $data['amount'];

            $account->balance -= $amount;
            $account->loadMissing('savingsProduct.charges');

            $withdrawalCharges = [];
            foreach ($this->resolveSelectedCharges($account) as $charge) {
                $chargeType = $charge['type'] ?? '';
                if ($chargeType !== 'withdraw' && $chargeType !== 'withdrawal') {
                    continue;
                }

                if (! $this->chargeAppliesToAmount($charge, $amount)) {
                    continue;
                }

                $chargeAmount = $this->calculateChargeAmount($charge, $amount);
                if ($chargeAmount > 0) {
                    $withdrawalCharges[] = [
                        'amount' => $chargeAmount,
                        'name' => $charge['name'] ?? null,
                        'gl_credit_account_id' => $charge['credit_account_id'] ?? $charge['gl_credit_account_id'] ?? null,
                        'is_reversible' => $charge['is_reversible'] ?? true,
                    ];
                }
            }

            $chargeTotal = collect($withdrawalCharges)->sum('amount');
            if ($chargeTotal >= $amount) {
                throw ValidationException::withMessages([
                    'amount' => ['Withdrawal charge must be less than the withdrawal amount.'],
                ]);
            }

            $account->save();

            $withdrawalTxn = Transaction::create([
                'reference' => $transactionRef,
                'receipt_number' => $receiptNumber,
                'member_id' => $account->member_id,
                'type' => 'withdrawal',
                'amount' => $amount,
                'charge_amount' => $chargeTotal,
                'payment_mode' => $data['payment_mode'] ?? null,
                'deposited_by' => $data['deposited_by'] ?? null,
                'transaction_date' => $data['deposit_date'],
                'account_id' => $account->id,
                'account_type' => SavingsAccount::class,
                'narration' => $data['narration'] ?? null,
                'created_by' => $createdBy,
                'branch_id' => $branchId,
            ]);

            foreach ($withdrawalCharges as $charge) {
                Transaction::create([
                    'reference' => $this->generateChargeReference(),
                    'receipt_number' => $receiptNumber,
                    'member_id' => $account->member_id,
                    'type' => 'charge',
                    'amount' => 0,
                    'charge_amount' => $charge['amount'],
                    'payment_mode' => $data['payment_mode'] ?? null,
                    'deposited_by' => 'System (Charge)',
                    'transaction_date' => $data['deposit_date'],
                    'account_id' => $account->id,
                    'account_type' => SavingsAccount::class,
                    'narration' => 'Withdrawal Charge'.($charge['name'] ? ': '.$charge['name'] : ''),
                    'charge_name' => $charge['name'],
                    'gl_credit_account_id' => $charge['gl_credit_account_id'],
                    'is_reversible' => $charge['is_reversible'],
                    'grouped_with' => $receiptNumber,
                    'created_by' => $createdBy,
                    'branch_id' => $branchId,
                ]);
            }

            $this->savingsJournal->postWithdrawal($withdrawalTxn, $account);

            $this->chargeApplication->applyForSavingsEvent(
                savingsAccountId: $account->id,
                eventType: 'withdraw',
                transactionAmount: (float) $withdrawalTxn->amount,
                transactionId: $withdrawalTxn->id,
                actorId: $withdrawalTxn->created_by ?? $createdBy,
            );

            return $withdrawalTxn;
        });
    }

    public function assertCanTransact(SavingsAccount $account, string $action): void
    {
        if ($account->status !== 'active') {
            throw ValidationException::withMessages([
                'savings_account_id' => ['Savings account is not active.'],
            ]);
        }

        $member = $account->member;
        if ($member && $member->status !== 'active') {
            throw ValidationException::withMessages([
                'member' => ['This member must be approved before any transactions can be made.'],
            ]);
        }
    }

    public function assertCanWithdraw(SavingsAccount $account, float $amount): void
    {
        if ($account->consider_min_balance) {
            $account->loadMissing('savingsProduct');
            $minimumBalance = (float) ($account->savingsProduct?->minimum_balance ?? 0);
            $withdrawable = (float) $account->balance - $minimumBalance;
            if ($amount > $withdrawable) {
                $fmt = number_format($minimumBalance, 2);
                $fmtMax = number_format(max($withdrawable, 0), 2);

                throw ValidationException::withMessages([
                    'amount' => ["Maximum withdrawable amount is UGX {$fmtMax} (minimum balance: UGX {$fmt})."],
                ]);
            }
        }

        if ($amount > (float) $account->balance) {
            throw ValidationException::withMessages([
                'amount' => ['Withdrawal amount exceeds account balance.'],
            ]);
        }
    }

    private function resolveSelectedCharges(SavingsAccount $account): array
    {
        $account->loadMissing('savingsProduct.charges');
        $productCharges = $account->savingsProduct?->charges ?? collect();
        $stored = $account->selected_charges ?? [];

        if (! empty($stored)) {
            if (is_array($stored[0] ?? null) && isset($stored[0]['type'])) {
                return $stored;
            }

            $productCharges = $productCharges->whereIn('id', $stored);
        }

        return $productCharges
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name ?? null,
                'type' => $c->type,
                'charge_type' => $c->charge_type,
                'amount' => (float) $c->amount,
                'minimum_amount' => (float) ($c->minimum_amount ?? 0),
                'maximum_amount' => (float) ($c->maximum_amount ?? 0),
                'is_reversible' => $c->is_reversible ?? true,
                'credit_account_id' => $c->credit_account_id ?? $c->gl_credit_account_id ?? null,
            ])
            ->values()
            ->toArray();
    }

    private function chargeAppliesToAmount(array $charge, float $amount): bool
    {
        $min = (float) ($charge['minimum_amount'] ?? 0);
        $max = (float) ($charge['maximum_amount'] ?? 0);

        return ! ($max > 0 && ($amount < $min || $amount > $max));
    }

    private function calculateChargeAmount(array $charge, float $amount): float
    {
        return ($charge['charge_type'] ?? '') === 'percentage'
            ? round($amount * ($charge['amount'] ?? 0) / 100, 2)
            : (float) ($charge['amount'] ?? 0);
    }

    private function generateReceiptNumber(): string
    {
        $date = date('Ymd');
        for ($i = 0; $i < 5; $i++) {
            $candidate = "RCPT-{$date}-".mt_rand(10000, 99999);
            if (! Transaction::where('receipt_number', $candidate)->exists()) {
                return $candidate;
            }
        }

        return "RCPT-{$date}-".uniqid();
    }

    private function generateTransactionReference(): string
    {
        $date = date('Ymd');
        for ($i = 0; $i < 5; $i++) {
            $candidate = "TXN-{$date}-".mt_rand(10000, 99999);
            if (! Transaction::where('reference', $candidate)->exists()) {
                return $candidate;
            }
        }

        return "TXN-{$date}-".uniqid();
    }

    private function generateChargeReference(): string
    {
        return 'CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
    }
}
