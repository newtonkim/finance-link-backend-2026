<?php

namespace App\Tenant\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TenantLoanService extends TenantLoanUpdateOrCreateService
{
    public function loanTransactionsTemplate()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                return $this->TryCatch(function () {
                    $req = request();
                    $fields = [
                        DB::raw("CONCAT(IFNULL(salutation,''), ' ', mbs.name) As salutation_name"),
                        'l.loan_no as loan_no',
                    ];

                    $query = DB::table('members AS mbs')
                        ->join('loans AS l', 'l.member_id', '=', 'mbs.id')
                        ->select([
                            ...$fields,
                            DB::raw('NULL AS payment_reference'),
                            DB::raw('NULL AS reschedule_id'),
                            DB::raw('NULL AS amount_paid'),
                            DB::raw('NULL AS principal_portion'),
                            DB::raw('NULL AS interest_portion'),
                            DB::raw('NULL AS penalty_portion'),
                            DB::raw('NULL AS charges_portion'),
                            DB::raw('NULL AS payment_date'),
                            DB::raw('NULL AS payment_method'),
                            DB::raw('NULL AS receipt_no'),
                            DB::raw('NULL AS collected_by'),
                            DB::raw('NULL AS reversal_flag'),
                            DB::raw('NULL AS reversed_by'),
                            DB::raw('NULL AS reversed_date'),
                            DB::raw('NULL AS transaction_ref'),
                            DB::raw('NULL AS created_at'),
                            'mbs.code As member_code',
                        ]);
                    if ($req->has('search_keyword')) {
                        $query = $this->dynamic_search_db_query($query, $req->search_keyword, $fields);
                    }
                    $branchId = $req->branch_id ?? request()->header('X-Acting-Branch-Id') ?? Auth::user()?->branch_id;
                    if ($branchId && ! is_numeric($branchId)) {
                        $branchId = null;
                    } elseif ($branchId) {
                        $branchId = (int) $branchId;
                    }

                    if ($branchId) {
                        $query->where('mbs.branch_id', $branchId);
                    }

                    return $query
                        ->whereNull('mbs.deleted_at')
                        ->orderBy('mbs.created_at', 'DESC')
                        ->paginate($this->perpage());
                });
            });
        });
    }

    public function loanRepaymentTemplate()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                return $this->TryCatch(function () {
                    $req = request();
                    $fields = [
                        DB::raw("CONCAT(IFNULL(salutation,''), ' ', mbs.name) As salutation_name"),
                        'mbs.code As member_code',
                        'l.loan_no as loan_no',
                    ];

                    $query = DB::table('members AS mbs')
                        ->join('loans AS l', 'l.member_id', '=', 'mbs.id')
                        ->select([
                            ...$fields,
                            DB::raw('null AS installment_no'),
                            DB::raw('null AS paid_at'),
                            DB::raw('null AS principal_due'),
                            DB::raw('null AS interest_due'),
                            DB::raw('null AS charges_due'),
                            DB::raw('null AS penalty_due'),
                            DB::raw('null AS total_due'),
                            DB::raw('null AS principal_paid'),
                            DB::raw('null AS interest_paid'),
                            DB::raw('null AS charges_paid'),
                            DB::raw('null AS penalty_paid'),
                            DB::raw('null AS outstanding_balance'),
                            DB::raw('null AS status'),
                            DB::raw('null AS due_date'),
                            'mbs.code As member_code',
                        ]);
                    if ($req->has('search_keyword')) {
                        $query = $this->dynamic_search_db_query($query, $req->search_keyword, $fields);
                    }
                    $branchId = $req->branch_id ?? request()->header('X-Acting-Branch-Id') ?? Auth::user()?->branch_id;
                    if ($branchId && ! is_numeric($branchId)) {
                        $branchId = null;
                    } elseif ($branchId) {
                        $branchId = (int) $branchId;
                    }

                    if ($branchId) {
                        $query->where('mbs.branch_id', $branchId);
                    }

                    return $query
                        ->whereNull('mbs.deleted_at')
                        ->orderBy('mbs.created_at', 'DESC')
                        ->paginate($this->perpage());
                });
            });
        });
    }

    public function loanApplicationDownloadTemplate()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                return $this->TryCatch(function () {
                    $req = request();
                    $rate_type = $req->rate_type ?? '';
                    $fields = [
                        DB::raw("CONCAT(IFNULL(salutation,''), ' ', mbs.name) As salutation_name"),
                        'mbs.code As member_code',
                        'br.name As branch_name',
                        'br.code As branch_code',
                    ];
                    $product = DB::table('loan_products')->where('id', $req->product_id)->first(['name', 'code']);
                    if (! $product) {
                        $product = DB::table('loan_products')->orderBy('id', 'DESC')->first(['name', 'code']);
                    }
                    $query = DB::table('members AS mbs')
                        ->join('branches AS br', 'br.id', '=', 'mbs.branch_id')
                        ->select([
                            ...$fields,
                            DB::raw('null AS requested_amount'),
                            DB::raw('null AS recommended_amount'),
                            DB::raw('null AS requested_term'),
                            DB::raw('null AS approved_amount'),
                            DB::raw('null AS final_approved_amount'),
                            DB::raw('null AS repayment_source'),
                            DB::raw('null AS status'),
                            DB::raw('null AS approved_term'),
                            DB::raw('null AS loan_duration'),
                            DB::raw('null AS interest_rate'),
                            DB::raw('null AS purpose'),
                            DB::raw('null AS loan_officer_code'),
                            DB::raw('null AS disbursed_by_code'),
                            DB::raw('null AS officer_notes'),
                            DB::raw('null AS loan_code'),
                            DB::raw('null AS term_months'),
                            DB::raw('null AS interest_method'),
                            DB::raw('null AS interest_period'),
                            DB::raw("'".($rate_type ?? '')."' AS rate_type"),
                            DB::raw("'".($product->code ?? '')."' AS product_code"),
                            DB::raw("'".($product->name ?? '')."' AS product_name"),
                            DB::raw('NOW() AS submitted_date'),
                            DB::raw('NOW() AS disbursement_date'),
                        ]);

                    if ($req->has('search_keyword')) {
                        $query = $this->dynamic_search_db_query($query, $req->search_keyword, $fields);
                    }

                    $branchId = $req->branch_id ?? request()->header('X-Acting-Branch-Id') ?? Auth::user()?->branch_id;
                    if ($branchId && ! is_numeric($branchId)) {
                        $branchId = null;
                    } elseif ($branchId) {
                        $branchId = (int) $branchId;
                    }

                    if ($branchId) {
                        $query->where('mbs.branch_id', $branchId);
                    }

                    return $query
                        ->whereNull('mbs.deleted_at')
                        ->orderBy('mbs.created_at', 'DESC')
                        ->paginate($this->perpage());
                });
            });
        });
    }

    public function getLoanApplicationsTransactions()
    {

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $branchId = $req['branch_id'] ?? request()->header('X-Acting-Branch-Id') ?? Auth::user()?->branch_id;
                if ($branchId && ! is_numeric($branchId)) {
                    $branchId = null;
                } elseif ($branchId) {
                    $branchId = (int) $branchId;
                }

                $status = isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;
                $data = DB::table('loan_transactions As lt')
                    ->when($branchId, function ($query) use ($branchId) {
                        $query->where('l.branch_id', $branchId);
                    })
                    ->when($status, function ($query) use ($status) {
                        $query->whereIn('lt.payment_method', $status);
                    })
                    ->when(! empty($req['search_keyword']) || ! empty($req['search_filter']), function ($query) use ($req) {
                        $this->dynamic_search_db_query(
                            $query,
                            $req['search_keyword'],
                            [
                                'l.loan_no',
                                'l.member_id',
                                'payment_date',
                                'payment_method',
                                'payment_id',
                                'lp.code',
                                'lp.name',
                                'm.code',
                                'm.name',
                                'lt.created_at',
                            ],
                            // filterable:["payment_date"=>"payment_date"]
                        );
                    })
                    ->join('loans As l', 'l.id', '=', 'lt.loan_id')
                    ->join('members As m', 'm.id', '=', 'l.member_id')
                    ->join('loan_products As lp', 'lp.id', '=', 'l.loan_product_id')
                    ->select([
                        'l.loan_application_id As application_id',
                        'm.name As member_name',
                        'payment_id as pay_reference',
                        'lp.name AS product_name',
                        'loan_id As loan_id',
                        'payment_date As payment_date',
                        'payment_method As payment_method',
                        'lt.created_at As created_at',
                        'l.loan_no As loan_no',
                        'l.member_id As member_id',
                        'lt.amount_paid AS amount_paid',
                    ])
                    ->orderBy('l.id', 'DESC')
                    ->paginate($this->perpage());

                return $data;
            });
        });
    }

    public function getLoanApplicationsList()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();

                $rawStatus = strtolower($req['status'] ?? 'all');
                // "Submitted" tab groups all in-review stages together
                $statusMap = [
                    'submitted' => ['submitted', 'under_review', 'awaiting_documents'],
                ];
                $status = ($rawStatus !== 'all' && $rawStatus !== '')
                    ? ($statusMap[$rawStatus] ?? [$rawStatus])
                    : null;
                $user = Auth::user();
                $isAdmin = $user && ($user->is_tenant_admin ?? false);

                $branchId = $req['branch_id'] ?? request()->header('X-Acting-Branch-Id') ?? ($isAdmin ? null : ($user->branch_id ?? null));
                if ($branchId && ! is_numeric($branchId)) {
                    $branchId = null;
                } elseif ($branchId) {
                    $branchId = (int) $branchId;
                }

                $data = DB::table('loan_applications As la')
                    ->whereNull('la.deleted_at')
                    ->when($branchId, function ($query) use ($branchId) {
                        $query->where(function ($q) use ($branchId) {
                            $q->where('la.branch_id', $branchId)
                                ->orWhereNull('la.branch_id');
                        });
                    })
                    ->when(! empty($req['search_keyword']), function ($query) use ($req) {
                        $this->dynamic_search_db_query(
                            $query,
                            $req['search_keyword'],
                            [
                                'la.application_no',
                                'la.member_id',
                                'la.loan_product_id',
                                'lp.code',
                                'lp.name',
                                'mb.code',
                                'mb.name',
                                'la.created_at',
                            ],
                            filterable: [
                                'submitted_date' => 'submitted_at',
                                'created_at' => 'la.submitted_at',
                            ]
                        );
                    })->when($status, function ($query) use ($status) {
                        $query->whereIn('la.status', $status);
                    })
                    ->join('members as mb', 'mb.id', '=', 'la.member_id')
                    ->join('loan_products as lp', 'lp.id', '=', 'la.loan_product_id')
                    ->select([
                        'requested_amount as amount',
                        'application_no as application_code',
                        'mb.code as member_code',
                        'la.status as status',
                        DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) AS member_name"),
                        DB::raw('DATEDIFF(Now(), la.submitted_at) AS submitted_date '),
                        'la.created_at as created_at',
                        'la.id as id',
                        // Enriched loan detail so the list can surface the decision-critical
                        // data (product, amount trail, term, rate, purpose, aging) without a
                        // per-row detail fetch.
                        'lp.name as product_name',
                        'lp.code as product_code',
                        'lp.interest_rate as interest_rate',
                        'la.requested_term as requested_term',
                        'la.recommended_amount as recommended_amount',
                        'la.approved_amount as approved_amount',
                        'la.approved_term as approved_term',
                        'la.purpose as purpose',
                        'la.submitted_at as submitted_at',
                    ])
                    ->orderBy('la.id', 'DESC')
                    ->paginate($this->perpage());

                $countStatus = DB::table('loan_applications As la')
                    ->whereNull('la.deleted_at')
                    ->select('la.status', DB::raw('count(*) as total'), DB::raw('COALESCE(SUM(la.requested_amount), 0) as total_amount'))
                    ->groupBy('la.status')
                    ->when($branchId, function ($query) use ($branchId) {
                        $query->where(function ($q) use ($branchId) {
                            $q->where('la.branch_id', $branchId)
                                ->orWhereNull('la.branch_id');
                        });
                    })
                    ->get();

                $countActive = DB::table('loans As la')
                    ->whereNull('la.deleted_at')
                    ->select('la.status', DB::raw('count(*) as total'))
                    ->groupBy('la.status')
                    ->where('la.status', 'active')
                    ->when($branchId, function ($query) use ($branchId) {
                        $query->where(function ($q) use ($branchId) {
                            $q->where('la.branch_id', $branchId)
                                ->orWhereNull('la.branch_id');
                        });
                    })
                    ->get();

                $payload = $data->toArray();
                $payload['count_status'] = [...$countActive, ...$countStatus];

                return $payload;
            });
        });
    }
}
