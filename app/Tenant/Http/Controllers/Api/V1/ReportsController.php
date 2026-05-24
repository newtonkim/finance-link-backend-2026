<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Exports\LoanArrearsExport;
use App\Exports\LoanBalancesExport;
use App\Exports\LoanCollectionsExport;
use App\Exports\LoanDisbursementExport;
use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Support\BranchContext;
use App\Tenant\Modules\Loans\Contracts\LoanAgingReportServiceInterface;
use App\Tenant\Modules\Loans\Services\LoanArrearsReportService;
use App\Tenant\Modules\Loans\Services\LoanBalancesReportService;
use App\Tenant\Modules\Loans\Services\LoanCollectionsReportService;
use App\Tenant\Modules\Loans\Services\LoanDisbursementReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class ReportsController extends Controller
{
    public function __construct(
        protected LoanAgingReportServiceInterface $agingService,
        protected LoanBalancesReportService $balancesService,
        protected LoanArrearsReportService $arrearsService,
        protected LoanCollectionsReportService $collectionsService,
        protected LoanDisbursementReportService $disbursementService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);
        $viewer = $request->user();

        return response()->json([
            'filters' => [
                'applied' => $filters,
                'options' => $this->filterOptions($viewer),
                'scope' => $viewer instanceof Staff ? BranchContext::scopeFor($viewer) : BranchContext::SCOPE_SELF,
            ],
            'daily_collection_report' => $this->dailyCollectionReport($filters),
            'branch_performance_comparison' => $this->branchPerformanceComparison($filters),
            'branch_reconciliation' => $this->branchReconciliation($filters),
            'staff_activity_report' => $this->staffActivityReport($filters),
        ]);
    }

    public function loanAging(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
            'bucket' => ['nullable', 'string', 'in:current,1-30,31-60,61-90,91-180,180+'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->agingService->summary($filters);

        return response()->json($result);
    }

    public function loanAgingPortfolioSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
        ]);

        $result = $this->agingService->portfolioSummary($filters);

        return response()->json($result);
    }

    public function loanBalances(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'status' => ['nullable', 'string', 'in:all,active,arrears,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = $this->balancesService->getReport($filters);

        return response()->json($result);
    }

    public function loanBalancesExport(Request $request)
    {
        $filters = $request->validate([
            'as_of_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'status' => ['nullable', 'string', 'in:all,active,arrears,closed'],
        ]);

        $data = $this->balancesService->getExportData($filters);

        $date = $filters['as_of_date'];
        $filename = "loan-balances-report-{$date}.xlsx";

        return Excel::download(new LoanBalancesExport($data, $date), $filename);
    }

    // ── Loan Collections Report ───────────────────────────────────────────────

    public function collectionsSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ]);

        return response()->json($this->collectionsService->getSummary($filters));
    }

    public function collectionsLoans(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->collectionsService->getReport($filters));
    }

    public function collectionsLoanTransactions(Request $request, int $loan): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ]);

        return response()->json(
            $this->collectionsService->getTransactionDetail($loan, $filters)
        );
    }

    public function collectionsExport(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ]);

        $data = $this->collectionsService->getExportData($filters);

        $from = $filters['date_from'];
        $to = $filters['date_to'];
        $filename = "loan-collections-report-{$from}-to-{$to}.xlsx";

        return Excel::download(
            new LoanCollectionsExport($data['loans'], $data['transactions'], $from, $to),
            $filename
        );
    }

    // ── Loan Arrears Report ───────────────────────────────────────────────────

    public function loanArrears(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'historical' => ['nullable', 'string', 'in:0,1,true,false'],
        ]);

        $isHistorical = filter_var($filters['historical'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $result = $isHistorical
            ? $this->arrearsService->getHistoricalReport($filters)
            : $this->arrearsService->getReport($filters);

        return response()->json($result);
    }

    public function loanArrearsComparison(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ]);

        return response()->json($this->arrearsService->getComparison($filters));
    }

    public function loanArrearsTrend(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'months' => ['nullable', 'integer', 'in:3,6,12'],
        ]);

        return response()->json($this->arrearsService->getTrend($filters));
    }

    public function loanArrearsInstallments(Request $request, int $loan): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => ['required', 'date'],
        ]);

        return response()->json(
            $this->arrearsService->getInstallmentDetail($loan, $validated['as_of_date'])
        );
    }

    public function loanArrearsExport(Request $request)
    {
        $filters = $request->validate([
            'as_of_date' => ['required', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
        ]);

        $data = $this->arrearsService->getExportData($filters);
        $date = $filters['as_of_date'];
        $filename = "loan-arrears-report-{$date}.xlsx";

        return Excel::download(new LoanArrearsExport($data, $date), $filename);
    }

    // ── Loan Disbursement Report ──────────────────────────────────────────────

    public function disbursementSummary(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
        ]);

        return response()->json($this->disbursementService->getSummary($filters));
    }

    public function disbursementLoans(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->disbursementService->getReport($filters));
    }

    public function disbursementTrend(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
            'months' => ['nullable', 'integer', 'in:3,6,12'],
        ]);

        return response()->json($this->disbursementService->getTrend($filters));
    }

    public function disbursementExport(Request $request)
    {
        $filters = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'loan_officer_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'loan_product_id' => ['nullable', 'integer', 'exists:tenant.loan_products,id'],
        ]);

        $data = $this->disbursementService->getExportData($filters);

        $from = $filters['date_from'];
        $to = $filters['date_to'];
        $filename = "loan-disbursement-report-{$from}-to-{$to}.xlsx";

        return Excel::download(
            new LoanDisbursementExport($data['summary'], $data['loans'], $from, $to),
            $filename
        );
    }

    public function filterOptions(?Staff $viewer = null): array
    {
        $viewer = $viewer instanceof Staff ? $viewer : auth()->user();
        $scope = $viewer instanceof Staff ? BranchContext::scopeFor($viewer) : BranchContext::SCOPE_SELF;

        return [
            'show_branch_filter' => $scope !== BranchContext::SCOPE_SELF,
            'branches' => $scope === BranchContext::SCOPE_SELF
                ? []
                : BranchContext::availableBranchesFor($viewer),
            'staff' => DB::connection('tenant')
                ->table('staff')
                ->whereNull('deleted_at')
                ->when($scope !== BranchContext::SCOPE_ALL, function ($query) use ($viewer, $scope) {
                    if ($scope === BranchContext::SCOPE_SELF) {
                        return $query->where('id', $viewer?->id);
                    }

                    return $query->where('branch_id', $viewer?->branch_id);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'branch_id', 'status']),
        ];
    }

    protected function dailyCollectionReport(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('transactions as t')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->whereNull('t.deleted_at')
            ->whereIn('t.type', ['deposit', 'withdrawal', 'charge']);

        $this->applyTransactionFilters($query, $filters);

        return $query
            ->selectRaw('DATE(t.transaction_date) as business_date')
            ->selectRaw('t.branch_id')
            ->selectRaw("COALESCE(b.name, 'Unassigned') as branch_name")
            ->selectRaw("SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END) as total_deposits")
            ->selectRaw("SUM(CASE WHEN t.type = 'withdrawal' THEN t.amount ELSE 0 END) as total_withdrawals")
            ->selectRaw("SUM(CASE WHEN t.type = 'charge' THEN t.amount ELSE 0 END) as total_charges")
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupByRaw('DATE(t.transaction_date), t.branch_id, b.name')
            ->orderBy('business_date', 'desc')
            ->orderBy('branch_name')
            ->get();
    }

    protected function branchPerformanceComparison(array $filters)
    {
        if ($this->viewerScope() === BranchContext::SCOPE_SELF) {
            return collect();
        }

        $memberSubquery = DB::connection('tenant')
            ->table('members')
            ->whereNull('deleted_at')
            ->when($this->effectiveBranchId($filters), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->selectRaw('branch_id, COUNT(*) as total_members')
            ->selectRaw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_members")
            ->groupBy('branch_id');

        $loanSubquery = DB::connection('tenant')
            ->table('loans')
            ->whereNull('deleted_at')
            ->when($this->effectiveBranchId($filters), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->when($filters['date_from'], fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'], fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->selectRaw('branch_id')
            ->selectRaw("SUM(CASE WHEN status IN ('disbursed', 'running') THEN outstanding_balance ELSE 0 END) as active_loan_portfolio")
            ->selectRaw('SUM(principal) as total_loan_principal')
            ->selectRaw('COUNT(*) as total_loans')
            ->groupBy('branch_id');

        return DB::connection('tenant')
            ->table('branches as b')
            ->leftJoinSub($memberSubquery, 'members_summary', fn ($join) => $join->on('members_summary.branch_id', '=', 'b.id'))
            ->leftJoinSub($loanSubquery, 'loan_summary', fn ($join) => $join->on('loan_summary.branch_id', '=', 'b.id'))
            ->whereNull('b.deleted_at')
            ->when($this->effectiveBranchId($filters), fn ($q, $branchId) => $q->where('b.id', $branchId))
            ->orderByDesc('active_members')
            ->orderByDesc('active_loan_portfolio')
            ->get([
                'b.id',
                'b.name',
                'b.code',
                DB::raw('COALESCE(members_summary.total_members, 0) as total_members'),
                DB::raw('COALESCE(members_summary.active_members, 0) as active_members'),
                DB::raw('COALESCE(loan_summary.total_loans, 0) as total_loans'),
                DB::raw('COALESCE(loan_summary.total_loan_principal, 0) as total_loan_principal'),
                DB::raw('COALESCE(loan_summary.active_loan_portfolio, 0) as active_loan_portfolio'),
            ]);
    }

    protected function branchReconciliation(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('transactions as t')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->whereNull('t.deleted_at');

        $this->applyTransactionFilters($query, $filters);

        return $query
            ->selectRaw('t.branch_id')
            ->selectRaw("COALESCE(b.name, 'Unassigned') as branch_name")
            ->selectRaw("SUM(CASE WHEN t.type IN ('deposit', 'transfer_in') THEN t.amount ELSE 0 END) as cash_in")
            ->selectRaw("SUM(CASE WHEN t.type IN ('withdrawal', 'transfer_out') THEN t.amount ELSE 0 END) as cash_out")
            ->selectRaw("SUM(CASE WHEN t.type = 'charge' THEN t.amount ELSE 0 END) as charges_collected")
            ->selectRaw("
                SUM(CASE WHEN t.type IN ('deposit', 'transfer_in') THEN t.amount ELSE 0 END)
                - SUM(CASE WHEN t.type IN ('withdrawal', 'transfer_out') THEN t.amount ELSE 0 END)
                as net_cash_position
            ")
            ->groupBy('t.branch_id', 'b.name')
            ->orderBy('branch_name')
            ->get();
    }

    protected function staffActivityReport(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('transactions as t')
            ->join('staff as s', 's.id', '=', 't.created_by')
            ->leftJoin('branches as b', 'b.id', '=', 't.branch_id')
            ->whereNull('t.deleted_at')
            ->whereNull('s.deleted_at');

        $this->applyTransactionFilters($query, $filters);

        if ($filters['staff_id']) {
            $query->where('s.id', $filters['staff_id']);
        }

        return $query
            ->selectRaw('s.id as staff_id')
            ->selectRaw('s.name as staff_name')
            ->selectRaw('s.email as staff_email')
            ->selectRaw('t.branch_id')
            ->selectRaw("COALESCE(b.name, 'Unassigned') as branch_name")
            ->selectRaw('COUNT(*) as total_transactions')
            ->selectRaw("SUM(CASE WHEN t.type = 'deposit' THEN t.amount ELSE 0 END) as deposits_processed")
            ->selectRaw("SUM(CASE WHEN t.type = 'withdrawal' THEN t.amount ELSE 0 END) as withdrawals_processed")
            ->selectRaw("SUM(CASE WHEN t.type = 'charge' THEN t.amount ELSE 0 END) as charges_processed")
            ->selectRaw("SUM(CASE WHEN t.type IN ('deposit', 'withdrawal') THEN t.amount ELSE 0 END) as total_value_processed")
            ->groupBy('s.id', 's.name', 's.email', 't.branch_id', 'b.name')
            ->orderByDesc('total_value_processed')
            ->get();
    }

    protected function applyTransactionFilters($query, array $filters): void
    {
        $effectiveBranchId = $this->effectiveBranchId($filters);
        if ($effectiveBranchId) {
            $query->where('t.branch_id', $effectiveBranchId);
        }

        if ($filters['date_from']) {
            $query->whereDate('t.transaction_date', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('t.transaction_date', '<=', $filters['date_to']);
        }

        if ($this->viewerScope() === BranchContext::SCOPE_SELF) {
            $query->where('t.created_by', auth()->id());
        }
    }

    protected function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:tenant.branches,id'],
            'staff_id' => ['nullable', 'integer', 'exists:tenant.staff,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        return array_merge([
            'branch_id' => null,
            'staff_id' => null,
            'date_from' => null,
            'date_to' => null,
        ], $validated);
    }

    protected function effectiveBranchId(array $filters): ?int
    {
        $viewer = auth()->user();

        if (! $viewer instanceof Staff) {
            return $filters['branch_id'] ?? null;
        }

        return match (BranchContext::scopeFor($viewer)) {
            BranchContext::SCOPE_ALL => $filters['branch_id'] ?? null,
            BranchContext::SCOPE_BRANCH, BranchContext::SCOPE_SELF => $viewer->branch_id ? (int) $viewer->branch_id : null,
            default => null,
        };
    }

    protected function viewerScope(): string
    {
        $viewer = auth()->user();

        return $viewer instanceof Staff
            ? BranchContext::scopeFor($viewer)
            : BranchContext::SCOPE_SELF;
    }
}
