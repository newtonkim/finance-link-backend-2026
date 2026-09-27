<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Loans\Models\GuarantorRecovery;
use App\Tenant\Modules\Loans\Models\GuarantorRecoveryLine;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Services\GuarantorRecoveryService;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Recovering defaulted loans from the borrower's and guarantors' savings, and the
 * recovery loans the borrower then owes the guarantors.
 */
class GuarantorRecoveryController extends Controller
{
    public function __construct(
        protected GuarantorRecoveryService $service,
    ) {}

    /** Preview who would pay what, before proposing a recovery. */
    public function plan(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate(['amount' => ['nullable', 'numeric', 'gt:0']]);

        return response()->json([
            'data' => $this->service->plan($loan, isset($validated['amount']) ? (float) $validated['amount'] : null),
        ]);
    }

    public function store(Request $request, Loan $loan): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $recovery = $this->service->propose(
            $loan,
            isset($validated['amount']) ? (float) $validated['amount'] : null,
            $validated['notes'] ?? null,
            $this->actorId(),
        );

        return response()->json([
            'message' => 'Recovery proposed. Another staff member must approve it.',
            'data' => $this->present($recovery),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending_approval,executed,rejected'],
            'recovery_loan_status' => ['nullable', 'string', 'in:open,settled'],
            'loan_id' => ['nullable', 'integer'],
        ]);

        $recoveries = GuarantorRecovery::query()
            ->with(['loan', 'member', 'lines.member', 'lines.savingsAccount', 'lines.guarantee.group', 'initiatedBy', 'approvedBy'])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['recovery_loan_status'] ?? null, fn ($q, $s) => $q->where('recovery_loan_status', $s))
            ->when($validated['loan_id'] ?? null, fn ($q, $id) => $q->where('loan_id', $id))
            ->latest('id')
            ->paginate(25);

        return response()->json([
            'data' => $recoveries->getCollection()->map(fn ($r) => $this->present($r)),
            'meta' => [
                'current_page' => $recoveries->currentPage(),
                'last_page' => $recoveries->lastPage(),
                'total' => $recoveries->total(),
            ],
        ]);
    }

    public function show(GuarantorRecovery $guarantorRecovery): JsonResponse
    {
        return response()->json(['data' => $this->present($guarantorRecovery, detailed: true)]);
    }

    public function approve(GuarantorRecovery $guarantorRecovery): JsonResponse
    {
        $recovery = $this->service->approve($guarantorRecovery, $this->actorId());

        return response()->json([
            'message' => 'Recovery approved and carried out.',
            'data' => $this->present($recovery, detailed: true),
        ]);
    }

    public function reject(Request $request, GuarantorRecovery $guarantorRecovery): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:1000']]);

        $recovery = $this->service->reject($guarantorRecovery, $validated['reason'], $this->actorId());

        return response()->json([
            'message' => 'Recovery rejected.',
            'data' => $this->present($recovery),
        ]);
    }

    /** Record the borrower's repayment of a recovery loan; it is paid into the guarantors' savings. */
    public function repay(Request $request, GuarantorRecovery $guarantorRecovery): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_date' => ['nullable', 'date'],
            'payment_mode' => ['nullable', 'string', 'in:cash,bank_transfer,mobile_money,cheque,teller,ussd'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        $this->service->repay($guarantorRecovery, (float) $validated['amount'], $validated, $this->actorId());

        return response()->json([
            'message' => 'Repayment recorded and paid to the guarantors.',
            'data' => $this->present($guarantorRecovery->fresh(), detailed: true),
        ], 201);
    }

    private function present(GuarantorRecovery $recovery, bool $detailed = false): array
    {
        $recovery->loadMissing(['loan', 'member', 'lines.member', 'lines.savingsAccount', 'lines.guarantee.group', 'initiatedBy', 'approvedBy']);
        $schedule = $this->service->schedule($recovery);
        $groupAccountNos = DB::connection('tenant')->table('group_savings_accounts')
            ->whereIn('id', $recovery->lines->pluck('group_savings_account_id')->filter())
            ->pluck('code', 'id');
        $today = now()->toDateString();
        $overdue = collect($schedule)
            ->filter(fn ($row) => $row['due_date'] < $today)
            ->sum(fn ($row) => $row['amount'] - $row['paid']);
        $next = collect($schedule)->first(fn ($row) => $row['status'] !== 'paid');

        $data = [
            'id' => $recovery->id,
            'code' => $recovery->code,
            'status' => $recovery->status,
            'loan_id' => $recovery->loan_id,
            'loan_no' => $recovery->loan?->loan_no,
            'member_id' => $recovery->member_id,
            'borrower_name' => $recovery->member?->name,
            'requested_amount' => (float) $recovery->requested_amount,
            'borrower_amount' => (float) $recovery->borrower_amount,
            'guarantor_amount' => (float) $recovery->guarantor_amount,
            'guarantor_amount_formatted' => TenantMoney::format($recovery->guarantor_amount),
            'notes' => $recovery->notes,
            'initiated_by' => $recovery->initiatedBy?->name,
            'initiated_by_id' => $recovery->initiated_by,
            'initiated_at' => $recovery->initiated_at,
            'approved_by' => $recovery->approvedBy?->name,
            'approved_at' => $recovery->approved_at,
            'rejected_at' => $recovery->rejected_at,
            'rejection_reason' => $recovery->rejection_reason,
            'executed_at' => $recovery->executed_at,
            'recovery_loan' => $recovery->recovery_loan_status ? [
                'status' => $recovery->recovery_loan_status,
                'term_months' => $recovery->recovery_loan_term_months,
                'repaid' => (float) $recovery->recovery_loan_repaid,
                'outstanding' => $recovery->recoveryLoanOutstanding(),
                'outstanding_formatted' => TenantMoney::format($recovery->recoveryLoanOutstanding()),
                'overdue' => round((float) $overdue, 2),
                'next_due_date' => $next['due_date'] ?? null,
                'next_due_amount' => isset($next) ? round($next['amount'] - $next['paid'], 2) : null,
            ] : null,
            'lines' => $recovery->lines->map(fn (GuarantorRecoveryLine $line) => [
                'id' => $line->id,
                'source' => $line->source,
                'member_id' => $line->member_id,
                'name' => $line->isGroup() ? $line->guarantee?->group?->name : $line->member?->name,
                'is_group' => $line->isGroup(),
                'savings_account_id' => $line->savings_account_id,
                'group_savings_account_id' => $line->group_savings_account_id,
                'account_no' => $line->isGroup() ? ($groupAccountNos[$line->group_savings_account_id] ?? null) : $line->savingsAccount?->account_no,
                'amount' => (float) $line->amount,
                'repaid_amount' => (float) $line->repaid_amount,
                'owed' => $line->source === GuarantorRecoveryLine::SOURCE_GUARANTOR ? $line->owed() : 0.0,
            ])->values(),
        ];

        if ($detailed) {
            $data['schedule'] = $schedule;
            $data['repayments'] = $recovery->repayments()->latest('id')->get()->map(fn ($r) => [
                'id' => $r->id,
                'amount' => (float) $r->amount,
                'payment_date' => $r->payment_date?->toDateString(),
                'payment_mode' => $r->payment_mode,
                'reference' => $r->reference,
                'allocations' => $r->allocations,
            ])->values();
        }

        return $data;
    }

    private function actorId(): int
    {
        return (int) (auth('tenant')->id() ?? auth()->id());
    }
}
