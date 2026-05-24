<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\LoanExport;
use App\Http\Controllers\Controller;
use App\Models\Scopes\BranchReadScope;
use App\Tenant\Http\Resources\LoanListResource;
use App\Tenant\Http\Resources\LoanResource;
use App\Tenant\Http\Resources\LoanTransactionResource;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Loans\Contracts\LoanActivityServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanDisbursementServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanPenaltyCalculatorServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanReschedule;
use App\Tenant\Modules\Loans\Models\LoanSchedule;
use App\Tenant\Modules\Loans\Services\LoanRescheduleService;
use App\Tenant\Support\TenantMoney;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LoanController extends Controller
{
    public function __construct(
        private readonly LoanActivityServiceInterface $activityService,
        private readonly LoanWriteOffServiceInterface $writeOffService,
    ) {}

    /**
     * GET /loans/summary
     * Tab counts: disbursed, approved, pending, arrears, all.
     * Optimised: uses conditional aggregation to run a single DB query
     * instead of 7+ separate COUNT(*) queries.
     */
    public function summary(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Acting-Branch-Id');

        // ── Single query for all loan-table counts ──────────────────────
        $loanQuery = Loan::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) AND parent_loan_id IS NULL THEN 1 ELSE 0 END) as disbursed', [
                LoanStatus::Disbursed->value,
                LoanStatus::Active->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as closed', [
                LoanStatus::Closed->value,
            ])
            ->selectRaw('SUM(CASE WHEN status = ? AND is_rescheduled = false THEN 1 ELSE 0 END) as arrears', [
                LoanStatus::Arrears->value,
            ])
            ->selectRaw('SUM(CASE WHEN is_rescheduled = true THEN 1 ELSE 0 END) as rescheduled');

        if ($branchId) {
            $loanQuery->where('branch_id', (int) $branchId);
        }

        $loanCounts = $loanQuery->first();

        // ── Single query for application-table counts ───────────────────
        $pendingStatuses = LoanApplication::getPendingStatuses();

        $appQuery = LoanApplication::query()
            ->selectRaw("SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved")
            ->selectRaw('SUM(CASE WHEN status IN ('.
                implode(',', array_fill(0, count($pendingStatuses), '?')).
                ') AND disbursed_loan_id IS NULL AND status != ? THEN 1 ELSE 0 END) as pending',
                [...$pendingStatuses, LoanApplication::STATUS_DISBURSED]
            );

        if ($branchId) {
            $appQuery->where('branch_id', (int) $branchId);
        }

        $appCounts = $appQuery->first();

        return response()->json([
            'disbursed' => (int) ($loanCounts->disbursed ?? 0),
            'approved' => (int) ($appCounts->approved ?? 0),
            'pending' => (int) ($appCounts->pending ?? 0),
            'arrears' => (int) ($loanCounts->arrears ?? 0),
            'closed' => (int) ($loanCounts->closed ?? 0),
            'all' => (int) ($loanCounts->total ?? 0),
            'rescheduled' => (int) ($loanCounts->rescheduled ?? 0),
        ]);
    }

    /**
     * GET /loans
     * Active loan portfolio with filters: status, member_id, loan_product_id.
     */
    public function index(Request $request): JsonResponse
    {
        $tab = $request->query('tab', 'all');

        // Approved / Pending tabs pull from loan_applications
        if (in_array($tab, ['approved', 'pending'])) {
            return $this->indexApplications($request, $tab);
        }

        $with = ['member', 'loanProduct', 'nextSchedule', 'loanApplication'];
        if ($tab === 'rescheduled') {
            $with[] = 'latestReschedule';
        }

        $query = Loan::query()
            ->with($with)
            ->orderByDesc('disbursed_at');

        match ($tab) {
            'disbursed' => $query->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active])->whereNull('parent_loan_id'),
            'arrears' => $query->where('status', LoanStatus::Arrears)->where('is_rescheduled', false),
            'rescheduled' => $query->where('is_rescheduled', true),
            default => null,
        };

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($memberId = $request->query('member_id')) {
            $query->where('member_id', (int) $memberId);
        }

        if ($productId = $request->query('loan_product_id')) {
            $query->where('loan_product_id', (int) $productId);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('loan_no', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'ilike', "%{$search}%"));
            });
        }

        if ($memberName = $request->query('member_name')) {
            $query->whereHas('member', fn ($q) => $q->where('name', 'ilike', "%{$memberName}%"));
        }

        if ($dateFrom = $request->query('approved_date_from')) {
            $query->whereHas('loanApplication', fn ($q) => $q->whereDate('approved_at', '>=', $dateFrom));
        }

        if ($dateTo = $request->query('approved_date_to')) {
            $query->whereHas('loanApplication', fn ($q) => $q->whereDate('approved_at', '<=', $dateTo));
        }

        if ($dateFrom = $request->query('disbursed_date_from')) {
            $query->whereDate('disbursed_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->query('disbursed_date_to')) {
            $query->whereDate('disbursed_at', '<=', $dateTo);
        }

        if ($branchId = $request->header('X-Acting-Branch-Id')) {
            $query->where('branch_id', (int) $branchId);
        }

        $perPage = min((int) ($request->query('per_page', 10)), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => LoanListResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function export(Request $request)
    {
        $filters = $request->all();
        $fileName = 'loans_export_'.now()->format('Y_m_d_His').'.xlsx';

        return Excel::download(new LoanExport($filters), $fileName);
    }

    /**
     * List loan applications for Approved / Pending tabs.
     */
    private function indexApplications(Request $request, string $tab): JsonResponse
    {
        $pendingStatuses = LoanApplication::getPendingStatuses();

        $query = LoanApplication::query()
            ->with(['member', 'loanProduct'])
            ->orderByDesc('created_at');

        if ($tab === 'approved') {
            $query->where('status', LoanApplication::STATUS_APPROVED);
        } else {
            $query->whereIn('status', $pendingStatuses)
                ->whereNull('disbursed_loan_id')
                ->where('status', '!=', LoanApplication::STATUS_DISBURSED);
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('application_no', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'ilike', "%{$search}%"));
            });
        }

        if ($branchId = $request->header('X-Acting-Branch-Id')) {
            $query->where('branch_id', (int) $branchId);
        }

        $perPage = min((int) ($request->query('per_page', 10)), 100);
        $paginated = $query->paginate($perPage);

        $items = $paginated->getCollection()->map(fn ($app) => [
            'id' => $app->id,
            'loan_no' => $app->application_no,
            'status' => $app->status,
            'principal' => $app->requested_amount,
            'principal_formatted' => TenantMoney::format($app->requested_amount),
            'outstanding_balance' => $app->requested_amount,
            'outstanding_balance_formatted' => TenantMoney::format($app->requested_amount),
            'disbursed_at' => null,
            'approved_at' => $app->approved_at?->format('Y-m-d'),
            'member' => $app->member ? [
                'id' => $app->member->id,
                'name' => $app->member->name,
                'member_number' => $app->member->member_number ?? $app->member->code ?? null,
            ] : null,
            'loan_product' => $app->loanProduct ? [
                'id' => $app->loanProduct->id,
                'name' => $app->loanProduct->name,
                'code' => $app->loanProduct->code,
            ] : null,
            'next_due_date' => null,
            'next_installment_amount' => null,
            '_source' => 'application',
        ]);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * Resolve a loan by ID without the BranchReadScope so that detail pages
     * are accessible regardless of the acting branch context. Authentication
     * is still required; list-level branch filtering is unaffected.
     */
    private function resolveLoan(int|string $id): Loan
    {
        $loan = Loan::withoutGlobalScope(BranchReadScope::class)->findOrFail($id);

        if ($loan->status === LoanStatus::Active || $loan->status === LoanStatus::Arrears || $loan->status === LoanStatus::Disbursed) {
            try {
                $calculator = app(LoanPenaltyCalculatorServiceInterface::class);
                $calculator->assessLoan($loan);
                // Refresh loan properties and schedules after calculation
                $loan->refresh();
            } catch (\Throwable $e) {
                // Silently bypass on-demand calculation failures so the page still loads
            }
        }

        return $loan;
    }

    /**
     * GET /loans/{id}
     */
    public function show(int|string $id): JsonResponse
    {
        $loan = $this->resolveLoan($id);
        $loan->load(['member', 'loanProduct.penaltyRules', 'loanProduct.charges', 'loanOfficer', 'disbursedBy', 'loanApplication', 'appliedCharges', 'parentLoan']);

        return response()->json(['data' => new LoanResource($loan)]);
    }

    /**
     * GET /loans/{id}/schedule
     */
    public function schedule(int|string $id): JsonResponse
    {
        $loan = $this->resolveLoan($id);
        $schedules = LoanSchedule::where('loan_id', $loan->id)
            ->orderBy('installment_no')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'installment_no' => $s->installment_no,
                'due_date' => $s->due_date?->format('Y-m-d'),
                'principal_due' => $s->principal_due,
                'interest_due' => $s->interest_due,
                'charges_due' => $s->charges_due,
                'penalty_due' => $s->penalty_due,
                'total_due' => $s->total_due,
                'principal_paid' => $s->principal_paid,
                'interest_paid' => $s->interest_paid,
                'charges_paid' => $s->charges_paid,
                'penalty_paid' => $s->penalty_paid,
                'outstanding_balance' => $s->outstanding_balance,
                'status' => $s->status,
                'paid_date' => $s->paid_date?->format('Y-m-d'),
                'is_overdue' => $s->is_overdue,
                'days_overdue' => $s->days_overdue,
                'reschedule_id' => $s->reschedule_id,
                'principal_due_formatted' => TenantMoney::format($s->principal_due),
                'interest_due_formatted' => TenantMoney::format($s->interest_due),
                'total_due_formatted' => TenantMoney::format($s->total_due),
                'outstanding_balance_formatted' => TenantMoney::format($s->outstanding_balance),
            ]);

        return response()->json(['data' => $schedules]);
    }

    /**
     * GET /loans/{id}/repayments
     */
    public function repayments(int|string $id, Request $request): JsonResponse
    {
        $loan = $this->resolveLoan($id);
        $query = $loan->repayments()
            ->with('collectedBy')
            ->where('reversal_flag', false)
            ->orderByDesc('payment_date');

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => LoanTransactionResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * GET /loans/{id}/ledger
     * Sub-ledger lines for this loan.
     */
    public function ledger(int|string $id, Request $request): JsonResponse
    {
        $loan = $this->resolveLoan($id);
        $lines = JournalEntryLine::with('journalEntry')
            ->where('loan_id', $loan->id)
            ->orderByDesc('id')
            ->paginate(min((int) ($request->query('per_page', 25)), 100));

        return response()->json([
            'data' => $lines->items(),
            'meta' => [
                'current_page' => $lines->currentPage(),
                'last_page' => $lines->lastPage(),
                'per_page' => $lines->perPage(),
                'total' => $lines->total(),
            ],
        ]);
    }

    /**
     * GET /loans/{id}/activities
     */
    public function activities(int|string $id): JsonResponse
    {
        $loan = $this->resolveLoan($id);
        $loan->load(['disbursedBy', 'repayments.collectedBy', 'statusHistory.changedBy']);

        $events = $this->activityService->getActivity($loan);

        return response()->json(['data' => $events]);
    }

    /**
     * POST /loans/{id}/reschedule/preview
     */
    public function reschedulePreview(Request $request, int|string $id, LoanRescheduleService $service): JsonResponse
    {
        $loan = $this->resolveLoan($id);

        $validated = $request->validate([
            'reschedule_type' => 'required|in:tenor_extension,rate_change,capitalization',
            'new_tenor_months' => 'sometimes|integer|min:1|max:120',
            'new_interest_rate' => 'sometimes|numeric|min:0|max:100',
            'capitalize_arrears' => 'sometimes|boolean',
            'penalties_waived' => 'sometimes|numeric|min:0',
            'interest_waived' => 'sometimes|numeric|min:0',
            'reschedule_date' => 'sometimes|date',
            'reason' => 'sometimes|string|max:500',
        ]);

        $preview = $service->preview($loan, $validated);

        return response()->json(['data' => $preview]);
    }

    /**
     * POST /loans/{id}/reschedule
     */
    public function reschedule(Request $request, int|string $id, LoanRescheduleService $service): JsonResponse
    {
        $loan = $this->resolveLoan($id);

        $validated = $request->validate([
            'reschedule_type' => 'required|in:tenor_extension,rate_change,capitalization',
            'new_tenor_months' => 'sometimes|integer|min:1|max:120',
            'new_interest_rate' => 'sometimes|numeric|min:0|max:100',
            'capitalize_arrears' => 'sometimes|boolean',
            'penalties_waived' => 'sometimes|numeric|min:0',
            'interest_waived' => 'sometimes|numeric|min:0',
            'reschedule_date' => 'sometimes|date',
            'reason' => 'required|string|max:500',
            'new_loan_product_id' => 'sometimes|nullable|integer|exists:tenant.loan_products,id',
            'apply_other_charges' => 'sometimes|boolean',
        ]);

        $reschedule = $service->execute($loan, $validated, auth()->id() ?? 1);

        return response()->json([
            'message' => 'Loan rescheduled successfully.',
            'data' => $reschedule,
        ]);
    }

    /**
     * GET /loans/{id}/reschedules
     */
    public function reschedules(int|string $id): JsonResponse
    {
        $loan = $this->resolveLoan($id);

        $reschedules = LoanReschedule::where('original_loan_id', $loan->id)
            ->with(['performedBy'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($r) use ($loan) {
                // Determine which rows this reschedule superseded.
                // It supersedes rows created by the PREVIOUS reschedule (or NULL if this is the first one).
                $previousReschedule = LoanReschedule::where('original_loan_id', $loan->id)
                    ->where('created_at', '<', $r->created_at)
                    ->orderBy('created_at', 'desc')
                    ->first();

                $prevId = $previousReschedule?->id;

                $supersededRows = LoanSchedule::where('loan_id', $loan->id)
                    ->where('reschedule_id', $prevId)
                    ->orderBy('installment_no')
                    ->get()
                    ->map(function (LoanSchedule $row) {
                        return array_merge($row->toArray(), [
                            'total_due_calc' => (float) $row->principal_due + (float) $row->interest_due + (float) $row->penalty_due + (float) $row->charges_due,
                        ]);
                    });

                return [
                    'id' => $r->id,
                    'reschedule_id' => $r->reschedule_id,
                    'reschedule_date' => $r->reschedule_date?->format('Y-m-d'),
                    'reschedule_type' => $r->reschedule_type,
                    'old_status' => $r->old_status,
                    'old_outstanding' => $r->old_outstanding,
                    'old_interest_rate' => $r->old_interest_rate,
                    'old_remaining_periods' => $r->old_remaining_periods,
                    'new_principal' => $r->new_principal,
                    'new_rate' => $r->new_rate,
                    'new_duration' => $r->new_duration,
                    'reason' => $r->reason,
                    'performed_by' => $r->performedBy?->name,
                    'superseded_schedule' => $supersededRows,
                ];
            });

        return response()->json(['data' => $reschedules]);
    }

    /**
     * PATCH /loans/{loan}/update-dates
     *
     * Allows editing disbursed_at and/or schedule_date independently.
     * Only changing schedule_date triggers schedule regeneration.
     * Changing disbursed_at alone just updates the date without touching the schedule.
     */
    public function updateDates(Loan $loan, Request $request, LoanDisbursementServiceInterface $disbursementService): JsonResponse
    {
        $validated = $request->validate([
            'disbursed_at' => 'sometimes|date',
            'schedule_date' => 'sometimes|date',
        ]);

        $scheduleChanged = isset($validated['schedule_date'])
            && $loan->schedule_date?->toDateString() !== Carbon::parse($validated['schedule_date'])->toDateString();

        // Persist the date changes on the loan
        $loan->update($validated);

        // Sync to the linked application
        if ($loan->loanApplication) {
            $appUpdate = [];
            if (isset($validated['disbursed_at'])) {
                $appUpdate['disbursed_at'] = $validated['disbursed_at'];
            }
            if (isset($validated['schedule_date'])) {
                $appUpdate['schedule_date'] = $validated['schedule_date'];
            }
            $loan->loanApplication->update($appUpdate);
        }

        // Regenerate schedule ONLY when schedule_date changed
        if ($scheduleChanged) {
            $disbursementService->regenerateSchedule(
                $loan,
                Carbon::parse($validated['schedule_date']),
            );
            $loan->refresh();

            // Assess penalties immediately if the new schedule has past-due items
            try {
                $calculator = app(LoanPenaltyCalculatorServiceInterface::class);
                $calculator->assessLoan($loan);
                $loan->refresh();
            } catch (\Throwable $e) {
                // Silently bypass to ensure the response still returns
            }
        }

        return response()->json([
            'message' => $scheduleChanged
                ? 'Loan dates updated and schedule regenerated.'
                : 'Loan dates updated successfully.',
            'data' => new LoanResource($loan),
        ]);
    }

    /**
     * POST /loans/{loan}/write-off
     */
    public function writeOff(Request $request, Loan $loan): JsonResponse
    {
        $data = $request->validate([
            'narration' => 'nullable|string|max:500',
        ]);

        $this->writeOffService->writeOff(
            loan: $loan,
            actorId: (int) auth()->id(),
            narration: $data['narration'] ?? '',
        );

        return response()->json([
            'message' => 'Loan written off successfully.',
            'loan_no' => $loan->loan_no,
            'status' => LoanStatus::WrittenOff->value,
        ]);
    }
}
