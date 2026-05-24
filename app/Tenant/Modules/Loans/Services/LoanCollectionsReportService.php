<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;

class LoanCollectionsReportService
{
    protected array $activeLoanStatuses = [
        'disbursed',
        'active',
        'running',
        'arrears',
        'defaulted',
    ];

    public function getSummary(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $periodLoans = $this->periodLoansBaseQuery($filters);

        $officerOutstanding = $this->outstandingByOfficerSubquery($filters);
        $branchOutstanding = $this->outstandingByBranchSubquery($filters);

        $byOfficerRows = (clone $periodLoans)
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoinSub($officerOutstanding, 'off_out', fn ($join) => $join->on('off_out.loan_officer_id', '=', 'l.loan_officer_id'))
            ->selectRaw("COALESCE(s.name, '—') as loan_officer_name")
            ->selectRaw('COUNT(DISTINCT l.id) as loan_count')
            ->selectRaw('COALESCE(SUM(due.amount_due), 0) as amount_due')
            ->selectRaw('COALESCE(SUM(col.amount_collected), 0) as amount_collected')
            ->selectRaw('COALESCE(MAX(off_out.outstanding_balance), 0) as outstanding_balance')
            ->groupBy('l.loan_officer_id', 's.name')
            ->orderBy('loan_officer_name')
            ->get();

        $byBranchRows = (clone $periodLoans)
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoinSub($branchOutstanding, 'br_out', fn ($join) => $join->on('br_out.branch_id', '=', 'l.branch_id'))
            ->selectRaw("COALESCE(b.name, 'Unassigned') as branch_name")
            ->selectRaw('COUNT(DISTINCT l.id) as loan_count')
            ->selectRaw('COALESCE(SUM(due.amount_due), 0) as amount_due')
            ->selectRaw('COALESCE(SUM(col.amount_collected), 0) as amount_collected')
            ->selectRaw('COALESCE(MAX(br_out.outstanding_balance), 0) as outstanding_balance')
            ->groupBy('l.branch_id', 'b.name')
            ->orderBy('branch_name')
            ->get();

        $byMethodRows = $this->transactionsBaseQuery($filters)
            ->selectRaw("COALESCE(NULLIF(lt.payment_method, ''), 'Unknown') as payment_method")
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(lt.amount_paid), 0) as amount_collected')
            ->groupByRaw("COALESCE(NULLIF(lt.payment_method, ''), 'Unknown')")
            ->orderBy('payment_method')
            ->get();

        $periodTotals = (clone $periodLoans)
            ->selectRaw('COALESCE(SUM(due.amount_due), 0) as amount_due')
            ->selectRaw('COALESCE(SUM(col.amount_collected), 0) as amount_collected')
            ->selectRaw('COALESCE(SUM(col.transaction_count), 0) as transaction_count')
            ->first();

        $outstandingTotals = DB::connection('tenant')
            ->query()
            ->fromSub($this->outstandingByLoanSubquery($filters, true), 'out_total')
            ->selectRaw('COALESCE(SUM(out_total.outstanding_balance), 0) as outstanding_balance')
            ->first();

        $amountDue = (float) ($periodTotals->amount_due ?? 0);
        $amountCollected = (float) ($periodTotals->amount_collected ?? 0);
        $transactionCount = (int) ($periodTotals->transaction_count ?? 0);
        $outstandingBalance = (float) ($outstandingTotals->outstanding_balance ?? 0);

        return [
            'by_officer' => $byOfficerRows->map(function ($row) {
                $amountDue = (float) $row->amount_due;
                $amountCollected = (float) $row->amount_collected;

                return [
                    'loan_officer_name' => $row->loan_officer_name,
                    'loan_count' => (int) $row->loan_count,
                    'amount_due' => $amountDue,
                    'amount_collected' => $amountCollected,
                    'collection_rate' => $this->collectionRate($amountDue, $amountCollected),
                    'outstanding_balance' => (float) $row->outstanding_balance,
                ];
            })->values()->all(),

            'by_branch' => $byBranchRows->map(function ($row) {
                $amountDue = (float) $row->amount_due;
                $amountCollected = (float) $row->amount_collected;

                return [
                    'branch_name' => $row->branch_name,
                    'loan_count' => (int) $row->loan_count,
                    'amount_due' => $amountDue,
                    'amount_collected' => $amountCollected,
                    'collection_rate' => $this->collectionRate($amountDue, $amountCollected),
                    'outstanding_balance' => (float) $row->outstanding_balance,
                ];
            })->values()->all(),

            'by_method' => $byMethodRows->map(fn ($row) => [
                'payment_method' => $row->payment_method,
                'transaction_count' => (int) $row->transaction_count,
                'amount_collected' => (float) $row->amount_collected,
            ])->values()->all(),

            'totals' => [
                'amount_due' => $amountDue,
                'amount_collected' => $amountCollected,
                'collection_rate' => $this->collectionRate($amountDue, $amountCollected),
                'outstanding_balance' => $outstandingBalance,
                'transaction_count' => $transactionCount,
            ],
        ];
    }

    public function getReport(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 25)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $offset = ($page - 1) * $perPage;

        $baseQuery = $this->loanRowsBaseQuery($filters);
        $countQuery = clone $baseQuery;

        $total = $countQuery->distinct()->count('l.id');

        $rows = $baseQuery
            ->orderBy('collection_rate')
            ->orderBy('l.id')
            ->offset($offset)
            ->limit($perPage)
            ->get();

        $data = $rows->map(fn ($row) => $this->mapLoanRow($row))->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? ($offset + 1) : null,
                'to' => $total > 0 ? min($offset + $perPage, $total) : null,
            ],
        ];
    }

    public function getTransactionDetail(int $loanId, array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $rows = $this->transactionsBaseQuery($filters)
            ->leftJoin('staff as collector', 'collector.id', '=', 'lt.collected_by')
            ->where('lt.loan_id', $loanId)
            ->selectRaw('lt.payment_date')
            ->selectRaw('lt.amount_paid')
            ->selectRaw('lt.principal_portion')
            ->selectRaw('lt.interest_portion')
            ->selectRaw('lt.charges_portion')
            ->selectRaw('lt.penalty_portion')
            ->selectRaw('lt.payment_method')
            ->selectRaw('lt.receipt_no')
            ->selectRaw("COALESCE(collector.name, '—') as collected_by_name")
            ->orderBy('lt.payment_date')
            ->orderBy('lt.id')
            ->get();

        return $rows->map(fn ($row) => [
            'payment_date' => $row->payment_date,
            'amount_paid' => (float) $row->amount_paid,
            'principal_portion' => (float) $row->principal_portion,
            'interest_portion' => (float) $row->interest_portion,
            'charges_portion' => (float) $row->charges_portion,
            'penalty_portion' => (float) $row->penalty_portion,
            'payment_method' => $row->payment_method,
            'receipt_no' => $row->receipt_no,
            'collected_by_name' => $row->collected_by_name,
        ])->values()->all();
    }

    public function getExportData(array $filters): array
    {
        $filters = $this->normalizedFilters($filters);

        $loanRows = (clone $this->loanRowsBaseQuery($filters))
            ->orderBy('collection_rate')
            ->orderBy('l.id')
            ->get()
            ->map(fn ($row) => $this->mapLoanRow($row))
            ->values()
            ->all();

        $transactionRows = $this->transactionsBaseQuery($filters)
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('staff as collector', 'collector.id', '=', 'lt.collected_by')
            ->selectRaw('l.loan_no')
            ->selectRaw('m.name as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw('lt.payment_date')
            ->selectRaw('lt.amount_paid')
            ->selectRaw('lt.principal_portion')
            ->selectRaw('lt.interest_portion')
            ->selectRaw('lt.charges_portion')
            ->selectRaw('lt.penalty_portion')
            ->selectRaw("COALESCE(NULLIF(lt.payment_method, ''), 'Unknown') as payment_method")
            ->selectRaw('lt.receipt_no')
            ->selectRaw("COALESCE(collector.name, '—') as collected_by_name")
            ->orderBy('lt.payment_date')
            ->orderBy('lt.id')
            ->get()
            ->map(fn ($row) => [
                'loan_no' => $row->loan_no,
                'member_name' => $row->member_name,
                'member_number' => $row->member_number,
                'payment_date' => $row->payment_date,
                'amount_paid' => (float) $row->amount_paid,
                'principal_portion' => (float) $row->principal_portion,
                'interest_portion' => (float) $row->interest_portion,
                'charges_portion' => (float) $row->charges_portion,
                'penalty_portion' => (float) $row->penalty_portion,
                'payment_method' => $row->payment_method,
                'receipt_no' => $row->receipt_no,
                'collected_by_name' => $row->collected_by_name,
            ])
            ->values()
            ->all();

        return [
            'loans' => $loanRows,
            'transactions' => $transactionRows,
        ];
    }

    protected function mapLoanRow(object $row): array
    {
        return [
            'loan_id' => (int) $row->loan_id,
            'loan_no' => $row->loan_no,
            'member_id' => (int) $row->member_id,
            'member_name' => $row->member_name,
            'member_number' => $row->member_number,
            'phone' => $row->phone,
            'branch_name' => $row->branch_name,
            'loan_officer_name' => $row->loan_officer_name,
            'product_name' => $row->product_name,
            'amount_due' => (float) $row->amount_due,
            'amount_collected' => (float) $row->amount_collected,
            'collection_rate' => (float) $row->collection_rate,
            'outstanding_balance' => (float) $row->outstanding_balance,
            'days_in_arrears' => (int) $row->days_in_arrears,
            'last_payment_date' => $row->last_payment_date,
        ];
    }

    protected function loanRowsBaseQuery(array $filters)
    {
        $dueSub = $this->dueByLoanSubquery($filters);
        $collectedSub = $this->collectedByLoanSubquery($filters);
        $outstandingSub = $this->outstandingByLoanSubquery($filters, false);
        $arrearsDaysSub = $this->arrearsDaysByLoanSubquery($filters['date_to']);
        $lastPaymentSub = $this->lastPaymentByLoanSubquery();

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->join('members as m', 'm.id', '=', 'l.member_id')
            ->leftJoin('branches as b', 'b.id', '=', 'l.branch_id')
            ->leftJoin('staff as s', 's.id', '=', 'l.loan_officer_id')
            ->leftJoin('loan_products as p', 'p.id', '=', 'l.loan_product_id')
            ->leftJoinSub($dueSub, 'due', fn ($join) => $join->on('due.loan_id', '=', 'l.id'))
            ->leftJoinSub($collectedSub, 'col', fn ($join) => $join->on('col.loan_id', '=', 'l.id'))
            ->leftJoinSub($outstandingSub, 'outstanding', fn ($join) => $join->on('outstanding.loan_id', '=', 'l.id'))
            ->leftJoinSub($arrearsDaysSub, 'arrears', fn ($join) => $join->on('arrears.loan_id', '=', 'l.id'))
            ->leftJoinSub($lastPaymentSub, 'last_payment', fn ($join) => $join->on('last_payment.loan_id', '=', 'l.id'))
            ->whereNull('l.deleted_at')
            ->whereNull('m.deleted_at')
            ->where(function ($where) {
                $where->whereNotNull('due.loan_id')
                    ->orWhereNotNull('col.loan_id');
            });

        $this->applyLoanFilters($query, $filters, 'l');

        return $query
            ->selectRaw('l.id as loan_id')
            ->selectRaw('l.loan_no')
            ->selectRaw('m.id as member_id')
            ->selectRaw('m.name as member_name')
            ->selectRaw('m.member_number')
            ->selectRaw("COALESCE(m.phone, '') as phone")
            ->selectRaw("COALESCE(b.name, 'Unassigned') as branch_name")
            ->selectRaw("COALESCE(s.name, '—') as loan_officer_name")
            ->selectRaw("COALESCE(p.name, '') as product_name")
            ->selectRaw('COALESCE(due.amount_due, 0) as amount_due')
            ->selectRaw('COALESCE(col.amount_collected, 0) as amount_collected')
            ->selectRaw('COALESCE(outstanding.outstanding_balance, 0) as outstanding_balance')
            ->selectRaw('COALESCE(arrears.days_in_arrears, 0) as days_in_arrears')
            ->selectRaw('last_payment.last_payment_date')
            ->selectRaw(
                'CASE
                    WHEN COALESCE(due.amount_due, 0) <= 0 THEN 0
                    ELSE LEAST(ROUND((COALESCE(col.amount_collected, 0) / NULLIF(due.amount_due, 0)) * 100, 1), 100)
                END as collection_rate'
            );
    }

    protected function periodLoansBaseQuery(array $filters)
    {
        $dueSub = $this->dueByLoanSubquery($filters);
        $collectedSub = $this->collectedByLoanSubquery($filters);

        $query = DB::connection('tenant')
            ->table('loans as l')
            ->leftJoinSub($dueSub, 'due', fn ($join) => $join->on('due.loan_id', '=', 'l.id'))
            ->leftJoinSub($collectedSub, 'col', fn ($join) => $join->on('col.loan_id', '=', 'l.id'))
            ->whereNull('l.deleted_at')
            ->where(function ($where) {
                $where->whereNotNull('due.loan_id')
                    ->orWhereNotNull('col.loan_id');
            });

        $this->applyLoanFilters($query, $filters, 'l');

        return $query;
    }

    protected function dueByLoanSubquery(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->join('loans as l', 'l.id', '=', 'rs.loan_id')
            ->whereNull('l.deleted_at')
            ->whereDate('rs.due_date', '>=', $filters['date_from'])
            ->whereDate('rs.due_date', '<=', $filters['date_to']);

        $this->applyLoanFilters($query, $filters, 'l');

        return $query
            ->selectRaw('rs.loan_id')
            ->selectRaw('COALESCE(SUM(rs.total_due), 0) as amount_due')
            ->groupBy('rs.loan_id');
    }

    protected function collectedByLoanSubquery(array $filters)
    {
        $query = $this->transactionsBaseQuery($filters);

        return $query
            ->selectRaw('lt.loan_id')
            ->selectRaw('COALESCE(SUM(lt.amount_paid), 0) as amount_collected')
            ->selectRaw('COUNT(*) as transaction_count')
            ->groupBy('lt.loan_id');
    }

    protected function outstandingByLoanSubquery(array $filters, bool $activeOnly)
    {
        $query = DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->join('loans as l', 'l.id', '=', 'rs.loan_id')
            ->whereNull('l.deleted_at');

        if ($activeOnly) {
            $query->whereIn('l.status', $this->activeLoanStatuses);
        }

        $this->applyLoanFilters($query, $filters, 'l');

        return $query
            ->selectRaw('rs.loan_id')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.total_due - COALESCE(rs.principal_paid, 0) - COALESCE(rs.interest_paid, 0) - COALESCE(rs.charges_paid, 0) - COALESCE(rs.penalty_paid, 0))), 0) as outstanding_balance')
            ->groupBy('rs.loan_id');
    }

    protected function outstandingByOfficerSubquery(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->join('loans as l', 'l.id', '=', 'rs.loan_id')
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $this->activeLoanStatuses);

        $this->applyLoanFilters($query, $filters, 'l');

        return $query
            ->selectRaw('l.loan_officer_id')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.total_due - COALESCE(rs.principal_paid, 0) - COALESCE(rs.interest_paid, 0) - COALESCE(rs.charges_paid, 0) - COALESCE(rs.penalty_paid, 0))), 0) as outstanding_balance')
            ->groupBy('l.loan_officer_id');
    }

    protected function outstandingByBranchSubquery(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->join('loans as l', 'l.id', '=', 'rs.loan_id')
            ->whereNull('l.deleted_at')
            ->whereIn('l.status', $this->activeLoanStatuses);

        $this->applyLoanFilters($query, $filters, 'l');

        return $query
            ->selectRaw('l.branch_id')
            ->selectRaw('COALESCE(SUM(GREATEST(0, rs.total_due - COALESCE(rs.principal_paid, 0) - COALESCE(rs.interest_paid, 0) - COALESCE(rs.charges_paid, 0) - COALESCE(rs.penalty_paid, 0))), 0) as outstanding_balance')
            ->groupBy('l.branch_id');
    }

    protected function arrearsDaysByLoanSubquery(string $asOfDate)
    {
        return DB::connection('tenant')
            ->table('loan_repayment_schedule as rs')
            ->whereDate('rs.due_date', '<=', $asOfDate)
            ->selectRaw('rs.loan_id')
            ->selectRaw('MAX(CASE WHEN GREATEST(0, rs.total_due - COALESCE(rs.principal_paid, 0) - COALESCE(rs.interest_paid, 0) - COALESCE(rs.charges_paid, 0) - COALESCE(rs.penalty_paid, 0)) > 0 THEN DATEDIFF(?, rs.due_date) ELSE 0 END) as days_in_arrears', [$asOfDate])
            ->groupBy('rs.loan_id');
    }

    protected function lastPaymentByLoanSubquery()
    {
        return DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->where('lt.reversal_flag', 0)
            ->whereNotNull('lt.payment_date')
            ->selectRaw('lt.loan_id')
            ->selectRaw('MAX(lt.payment_date) as last_payment_date')
            ->groupBy('lt.loan_id');
    }

    protected function transactionsBaseQuery(array $filters)
    {
        $query = DB::connection('tenant')
            ->table('loan_transactions as lt')
            ->join('loans as l', 'l.id', '=', 'lt.loan_id')
            ->whereNull('l.deleted_at')
            ->where('lt.reversal_flag', 0)
            ->whereNotNull('lt.payment_date')
            ->whereDate('lt.payment_date', '>=', $filters['date_from'])
            ->whereDate('lt.payment_date', '<=', $filters['date_to']);

        $this->applyLoanFilters($query, $filters, 'l');

        return $query;
    }

    protected function collectionRate(float $amountDue, float $amountCollected): float
    {
        if ($amountDue <= 0) {
            return 0.0;
        }

        return max(0.0, min(round(($amountCollected / $amountDue) * 100, 1), 100.0));
    }

    protected function normalizedFilters(array $filters): array
    {
        $normalized = array_merge([
            'date_from' => null,
            'date_to' => null,
            'branch_id' => null,
            'loan_officer_id' => null,
            'per_page' => 25,
            'page' => 1,
        ], $filters);

        if (empty($normalized['date_from'])) {
            $normalized['date_from'] = now()->startOfMonth()->toDateString();
        }

        if (empty($normalized['date_to'])) {
            $normalized['date_to'] = now()->toDateString();
        }

        return $normalized;
    }

    protected function applyLoanFilters($query, array $filters, string $loanAlias = 'l'): void
    {
        if (! empty($filters['branch_id'])) {
            $query->where("{$loanAlias}.branch_id", (int) $filters['branch_id']);
        }

        if (! empty($filters['loan_officer_id'])) {
            $query->where("{$loanAlias}.loan_officer_id", (int) $filters['loan_officer_id']);
        }

        $this->applyBranchScope($query, $loanAlias);
    }

    protected function applyBranchScope($query, string $loanAlias = 'l'): void
    {
        $user = auth()->user();

        if (! $user instanceof Staff) {
            return;
        }

        $scope = BranchContext::scopeFor($user);

        if ($scope !== BranchContext::SCOPE_ALL && $user->branch_id) {
            $query->where("{$loanAlias}.branch_id", (int) $user->branch_id);
        }
    }
}
