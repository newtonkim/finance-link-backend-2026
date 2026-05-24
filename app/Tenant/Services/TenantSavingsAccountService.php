<?php

namespace App\Tenant\Services;

use App\Exports\GroupMemberSavingsExport;
use App\Exports\GroupSavingsExport;
use App\Tenant\Modules\Settings\Models\SaccoBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class TenantSavingsAccountService extends TenantSavingsAccountUpdateOrCreateService
{
    protected $savingsAccountDbFields = [
        'ac.code AS account_code',
        'ac.id As id',
        'ac.account_type AS type',
        'ac.status AS status',
        'ac.balance As blc',
        'ac.created_at AS created_at',
    ];

    protected $grSavingsAccountDbFields = [
        'gac.code AS group_code',
        'gac.primary_contact_phone AS phone',
        'gac.image_path As group_log',
        'gac.name As group_name',
        'gac.id As id',
        'gac.date_created AS dcreated',
        'gac.location AS location',
        'gac.status As status',
        'gac.created_at AS created_at',
    ];

    protected $transferDbFields = [
        'sacct.id As id',
        'sacct.code AS code',
        'sacct.amount AS transfer_amount',
        'sacct.status As status',
        'sacct.created_at AS created_at',
    ];

    public function groupAccountTransactions()
    {
        return $this->TryCatch(function () {
            return $this->TryCatch(function () {
                $req = request();
                $groupId = $req['group_id'] ?? null;
                $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;

                return DB::table('group_savings_accounts as sgac')
                    ->join('savings_products AS sp', 'sp.id', '=', 'sgac.savings_product_id')
                    ->join('transactions AS tr', 'tr.group_savings_account_id', '=', 'sgac.id')
                    ->join('members AS mb', 'mb.id', '=', 'tr.member_id')
                    ->select([
                        'sgac.id AS id',
                        'tr.reference AS code',
                        'mb.id AS member_id',
                        'mb.code AS member_code',
                        'mb.phone AS member_phone',
                        DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) AS member_name"),
                        'sp.name AS product',
                        'sgac.balance AS blc',
                        'sgac.status AS status',
                        'sgac.created_at AS created_at',
                        'tr.id AS tr_id',
                        'tr.narration AS narration',
                        'tr.charge_amount AS charge',
                        'tr.amount AS amount',
                        'tr.transaction_date AS transaction_date',
                    ])
                    ->where('sgac.savings_group_id', $groupId)
                    ->when($staus != null, function ($query) use ($staus) {
                        $query->whereIn('tr.type', $staus);
                    })
                    ->orderBy('tr.id', 'DESC')
                    // ->orderBy('sgac.id', 'DESC')
                    ->whereNull('tr.deleted_at')->orderBy('tr.id', 'DESC')->paginate($this->perpage());
            });
        });
    }

    public function groupAccountTransactionsDownload()
    {
        // return $this->TryCatch(function () {
        $req = request();
        $groupId = $req['group_id'];
        $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
        $query = DB::table('group_savings_accounts as sgac')
            ->join('savings_products AS sp', 'sp.id', '=', 'sgac.savings_product_id')
            ->join('transactions AS tr', 'tr.group_savings_account_id', '=', 'sgac.id')
            ->join('members AS mb', 'mb.id', '=', 'tr.member_id')
            ->select([
                'sgac.id AS id',
                'tr.reference AS code',
                'mb.code AS member_code',
                'mb.phone AS member_phone',
                DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) AS member_name"),
                'sp.name AS product',
                'sgac.balance AS blc',
                'sgac.status AS status',
                'sgac.created_at AS created_at',
                'tr.narration AS narration',
                'tr.charge_amount AS charge',
                'tr.amount AS amount',
                'tr.transaction_date AS transaction_date',
            ])
            ->where('sgac.savings_group_id', $groupId)
            ->when($staus != null, function ($query) use ($staus) {
                $query->whereIn('tr.type', $staus);
            })
            ->orderBy('sgac.id')
            ->whereNull('tr.deleted_at')
            ->orderBy('tr.id', 'DESC')
            ->when($req->has('search_keyword'), function ($query) use ($req) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    'mb.name',
                    'mb.code',
                    'mb.phone',
                    'sp.name',
                    'tr.reference',
                ]);
            })
            ->paginate($this->perpage());
        $data = $query->items();

        $fileName = 'group-saving-transactions-' . now()->format('Y-m-d_H-i-s') . '.pdf';
        if ($req['value'] == 'PDF') {
            $pdf = Pdf::loadView('saving-account.print-group-saving-account-transactions', array_merge(['data' => $data, 'paperSize' => $req['scale']], $this->brandingViewData()));

            return $pdf->download($fileName);
        }

        //  php artisan make:export GroupSavingsExport
        return Excel::download(
            new GroupSavingsExport($data),
            $fileName . '.xlsx'
        );

        // });
    }

    public function downloadGroupMembersList()
    {
        // return $this->TryCatch(function () {
        return $this->TryCatch(function () {
            $req = request();
            $groupId = $req['group_id'];
            $getMembers = [];
            $groupName = DB::table('savings_groups')->where('id', $groupId)->first(['name As group_name', 'code As group_code']);

            $query = DB::table('savings_group_members as sgm')
                ->whereRaw('sgm.savings_group_id=?', [$groupId])
                ->join('members AS mb', 'sgm.member_id', '=', 'mb.id')
                ->select([
                    'mb.id',
                    'mb.phone as phone',
                    'mb.code as member_code',
                    'mb.status as member_status',
                    'sgm.code as member_group_code',
                    DB::raw('(SELECT SUM(balance) from loans where member_id=mb.id) as loan_balance'),
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) as member_name"),
                    'sgm.created_at AS created_at',
                ])
                ->whereRaw('sgm.savings_group_id=?', [$groupId])
                ->orderBy('sgm.id')
                ->chunk(100, function ($rows) use (&$getMembers) {
                    foreach ($rows as $row) {
                        $getMembers[] = $row;
                    }
                });

            $fileName = 'group-memebers-' . now()->format('Y-m-d_H-i-s') . '.pdf';
            if ($req['value'] == 'PDF') {
                $pdf = Pdf::loadView('saving-account.print-group-saving-account-members', array_merge(['data' => $getMembers, 'group_name' => $groupName->group_name, 'group_code' => $groupName->group_code, 'paperSize' => $req['scale']]));

                return $pdf->download($fileName);
            }

            //  php artisan make:export GroupSavingsExport
            return Excel::download(
                new GroupMemberSavingsExport($getMembers),
                $fileName . '.xlsx'
            );
        });

        // });
    }

    public function printGroupAccountTransactions()
    {
        // return $this->TryCatch(function () {
        $req = request();
        $groupId = $req['group_id'];
        $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
        $query = DB::table('group_savings_accounts as sgac')
            ->join('savings_products AS sp', 'sp.id', '=', 'sgac.savings_product_id')
            ->join('transactions AS tr', 'tr.group_savings_account_id', '=', 'sgac.id')
            ->join('members AS mb', 'mb.id', '=', 'tr.member_id')
            ->select([
                'sgac.id AS id',
                'tr.reference AS code',
                'mb.code AS member_code',
                'mb.phone AS member_phone',
                DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) AS member_name"),
                'sp.name AS product',
                'sgac.balance AS blc',
                'sgac.status AS status',
                'sgac.created_at AS created_at',
                'tr.narration AS narration',
                'tr.charge_amount AS charge',
                'tr.amount AS amount',
                'tr.transaction_date AS transaction_date',
            ])
            ->where('sgac.savings_group_id', $groupId)
            ->when($staus != null, function ($query) use ($staus) {
                $query->whereIn('tr.type', $staus);
            })
            ->orderBy('sgac.id')
            ->whereNull('tr.deleted_at')
            ->orderBy('tr.id', 'DESC')
            ->when($req->has('search_keyword'), function ($query) use ($req) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    'mb.name',
                    'mb.code',
                    'mb.phone',
                    'sp.name',
                    'tr.reference',
                ]);
            })
            ->paginate($this->perpage());
        $data = $query->items();

        return view('saving-account.print-group-saving-account-transactions', array_merge(['data' => $data, 'paperSize' => $req['scale']], $this->brandingViewData()))->render();

        // });
    }

    public function memberAccountDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('savings_accounts as ac')
                ->leftJoin('members AS mb', 'ac.member_id', '=', 'mb.id')
                ->leftJoin('savings_products AS sp', 'sp.id', '=', 'ac.savings_product_id')
                ->select([
                    ...$this->savingsAccountDbFields,
                    'ac.member_id',
                    'sp.name As product',
                    'mb.phone As phone',
                    'ac.interest_rate As intrest',
                    'ac.account_opening_balance As opblc',
                    'ac.consider_min_balance As minBalance',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                ])->whereRaw('ac.id=?', $req['id'])->first();
            $collection = [];
            if ($query) {
                // let look for transactions
                DB::table('transactions')
                    ->where('account_id', $query->id)
                    ->orderBy('id', 'desc')
                    ->select([
                 
                        //  DB::raw('(SELECT SUM(amount+charge_amount) FROM transactions WHERE umbrella_code = transactions.umbrella_code AND type = "deposit" AND id =transactions.id) AS amount'),
                        
                        'charge_amount as charge',
                        DB::raw('deposited_amount_before_charge total'),
                        'transactions.is_reversed as reversed',
                        'type as type',
                        'payment_mode as mode',
                        'deposited_by as by',
                        'reference',
                        'transaction_date',
                        'narration',
                        'created_at',
                    ])
                    ->orderByRaw("
                    CASE
                    WHEN type Like '%charge%' THEN 1
                    ELSE 2
                    END
                    ")
                    ->orderBy('id', 'Asc')
                    ->groupBy('id')
                    ->chunk(400, function ($data) use (&$collection) {
                        foreach ($data as $value) {
                            $collection[] = ($value);
                        }
                    });
                $query->transactionList = $collection;
            }

            return $query;
        });
    }

    public function groupAccountDownloadTemplate()
    {
        return $this->TryCatch(function () {
            return [
                "group_type" => "group type",
                "group_code" => "group code",
                "savings_product" => "product code",
                "opening_balance" => "opening balance",
                "initial_deposit" => "initial deposit",
                "group_name" => "group name",
                "primary_contact_phone" => "group number",
                "date_created" => "created time",
                "group_location" => "group location",
                "group_description" => "group desction",
            ];
        });
    }
    public function groupMemberDownloadTemplate()
    {
        return $this->TryCatch(function () {
            $req = request();
            $group_code = DB::table('savings_groups as sg')->where('id', $req['groud_id'])->first(['code'])->code;
            $query = DB::table('members as mb')
                ->join('branches as br', 'br.id', '=', 'mb.branch_id')
                ->select([
                    'mb.code AS member_code',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                    // DB::raw('null As Status'), // active/domant
                    DB::raw("'" . $group_code . "' AS group_code"),
                    DB::raw("'member' As role"), // active/domant
                ]);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mb.name', 'mb.code']);
            }

            return $query->whereRaw('mb.branch_id=?', $req->branch_id)
                ->whereNull('mb.deleted_at')->orderBy('mb.id', 'DESC')->paginate($this->perpage());
        });
    }
    public function downloadMemberAccountImportTemplate()
    {
        return $this->TryCatch(function () {
            $req = request();
            $query = DB::table('members as mb')
                ->join('branches as br', 'br.id', '=', 'mb.branch_id')
                ->select([
                    'mb.code AS code',
                    'br.code AS branch_code',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                    DB::raw('0 As new_account'), // 1/0
                    DB::raw('null As Status'), // active/domant
                    DB::raw('1 As consider_min_balance'), // // 1/0
                    DB::raw('null As product_code'), // product_id
                    DB::raw('0 As opening_balance'),
                    DB::raw('0 As in_deposit'),
                    DB::raw('null As account_code'),
                ]);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mb.name', 'mb.code']);
            }

            return $query->whereRaw('mb.branch_id=?', $req->branch_id)
                ->whereNull('mb.deleted_at')->orderBy('mb.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function downloadMemberAccountDepositTemplate()
    {
        return $this->TryCatch(function () {
            $req = request();
            $query = DB::table('members as mb')
                ->join('savings_accounts as sa', 'sa.member_id', '=', 'mb.id')
                ->join('branches as br', 'mb.branch_id', '=', 'br.id')
                ->select([
                    'br.code AS branch_code',
                    'mb.code AS code',
                    DB::raw('sa.code As account_code'),
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                    // DB::raw("null As product_code"), //product_id
                    DB::raw('0 As amount'), // 1000
                    DB::raw('0 As charge'),
                    DB::raw("CONCAT('CASH') As method"),
                    DB::raw("CONCAT('" . ($req['type'] ?? 'deposit') . "') as type"),
                    DB::raw('null As date'),
                    DB::raw('null As narration'),
                    DB::raw('0 As charge_by_system'),
                ]);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mb.name', 'mb.code']);
            }

            return $query->whereRaw('mb.branch_id=?', $req->branch_id)
                ->whereNull('mb.deleted_at')->orderBy('mb.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function transferList()
    {
        return $this->TryCatch(function () {
            // Debunking method let try it
            $req = request();
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('savings_account_transfers as sacct')
                ->whereRaw('sacct.branch_id=?', $req->branch_id)
                ->whereNull('sacct.deleted_at')->orderBy('id', 'DESC')
                ->Join('savings_accounts AS acFrom', 'acFrom.id', '=', 'sacct.from_account_id')
                ->Join('savings_accounts AS acTo', 'acTo.id', '=', 'sacct.to_account_id')
                ->Join('savings_products AS sp', 'sp.id', '=', 'acTo.savings_product_id')
                ->Join('savings_products AS sp2', 'sp2.id', '=', 'acFrom.savings_product_id')
                ->Join('members AS mb', 'mb.id', '=', 'acTo.member_id')
                ->select([
                    DB::raw('count(sacct.id) as count'),
                    DB::raw('MAX(sacct.id) as id'),
                    DB::raw('MAX(sacct.code) as code'),
                    DB::raw('SUM(sacct.amount) as transfer_amount'),
                    DB::raw('MAX(sacct.status) as status'),
                    DB::raw('MAX(sacct.created_at) as created_at'),
                    DB::raw('MAX(acTo.balance) as account_balance'),
                    DB::raw('MAX(sp.name) as transfer_to_product'),
                    DB::raw('MAX(sp2.name) as transfer_from_product'),
                    DB::raw('MAX(acTo.code) as transfer_to_account'),
                    DB::raw('MAX(acFrom.code) as transfer_from_account'),
                    DB::raw("MAX(CONCAT(IFNULL(salutation,''), ' ', mb.name)) AS member_name"),
                    'mb.id as member_id',
                ]);
            if ($staus != null) {
                $query->whereIn('sacct.status', $staus);
            }

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    ...$this->transferDbFields,
                    'sp.name',
                    'sp2.code',
                    'sp.code',
                    'mb.code',
                    'acTo.code',
                    'acFrom.code',
                    'sacct.code',
                    'sp.name',
                    'mb.name',
                ]);
            }

            return $query
                ->groupBy('mb.id')

                ->paginate($this->perpage());
        });
    }

    public function transferDetails()
    {
        return $this->TryCatch(function () {
            $req = request();
            $query = DB::table('savings_account_transfers as sacct')
                ->whereRaw('sacct.id=?', $req['id'])
                ->Join('savings_accounts AS acFrom', 'acFrom.id', '=', 'sacct.from_account_id')
                ->LeftJOIN('branches as brch', 'brch.id', '=', 'sacct.branch_id')
                ->Join('savings_accounts AS acTo', 'acTo.id', '=', 'sacct.to_account_id')
                ->Join('savings_products AS sp', 'sp.id', '=', 'acTo.savings_product_id')
                ->Join('savings_products AS sp2', 'sp2.id', '=', 'acFrom.savings_product_id')
                ->Join('members AS mb', 'mb.id', '=', 'acTo.member_id')
                ->Join('members AS from', 'from.id', '=', 'acFrom.member_id')
                ->Join('staff AS staff', 'staff.id', '=', 'sacct.created_by')
                ->select([
                    ...$this->transferDbFields,
                    "sacct.description as narration",
                    "staff.name as created_by",
                    "sacct.transaction_date as transaction_date",
                    DB::raw("CONCAT('" . htmlspecialchars(config('app.url')) . "','/', mb.profile_path) AS to_member_image"),
                    DB::raw("CONCAT('" . htmlspecialchars(config('app.url')) . "','/', from.profile_path) AS from_member_image"),
                    'sp.code AS to_product_code',
                    'sp2.code AS from_product_code',
                    DB::raw("JSON_OBJECT('branch',brch.name,'code',brch.code,'address',brch.address,'phone',brch.phone) AS branch_details "),



                    DB::raw('(SELECT SUM(amount) 
                  FROM savings_account_transfers 
                  WHERE to_account_id = sacct.to_account_id OR from_account_id = sacct.to_account_id OR to_account_id = sacct.from_account_id OR from_account_id = sacct.from_account_id) 
                  as total_transfer'),

                    'acTo.balance AS account_balance',
                    'sp.name AS transfer_to_product',
                    'sp2.name AS transfer_from_product',
                    'sacct.description AS natation',

                    'mb.id AS member_id',
                    'mb.code AS member_code',
                    DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) As member_name"),
                ])->first();
            $query->branch_details = $this->isJSONToArray($query->branch_details);

            $chunkSize = 100;
            $transfetList = [];

            DB::table('savings_account_transfers as sacct')
                ->join('savings_accounts AS acFrom', 'acFrom.id', '=', 'sacct.from_account_id')
                ->join('savings_accounts AS acTo', 'acTo.id', '=', 'sacct.to_account_id')
                ->join('savings_products AS sp', 'sp.id', '=', 'acTo.savings_product_id')
                ->join('savings_products AS sp2', 'sp2.id', '=', 'acFrom.savings_product_id')
                ->join('members AS mb2', 'mb2.id', '=', 'acFrom.member_id')
                ->join('members AS mb', 'mb.id', '=', 'acTo.member_id')
                ->where('acTo.member_id', $query->member_id)
                ->orderBy('sacct.id')
                ->select([
                    ...$this->transferDbFields,
                    'acTo.balance AS account_balance',
                    'sp.name AS transfer_to_product',
                    'sp2.name AS transfer_from_product',
                    DB::raw("CONCAT(IFNULL(mb2.salutation,''), ' ', mb2.name) As from_member_name"),
                    DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) As to_member_name"),
                    'acFrom.code AS from_account_code',
                    'acTo.code AS to_account_code',
                    // DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) As to_member_name"),
                ])
                ->chunk($chunkSize, function ($transfers) use (&$transfetList) {
                    foreach ($transfers as $transfer) {
                        $transfetList[] = $transfer;
                    }
                });

            $getTransaction = DB::table('transactions AS trs')
                ->LeftJOIN('staff as stf', 'stf.id', '=', 'trs.created_by')
                // ->LeftJOIN('branches as brch', 'brch.id', '=', 'trs.branch_id')
                ->leftJoin('savings_account_transfers as sacct', 'sacct.id', '=', 'trs.savings_account_transfers_id')
                ->Join('savings_accounts AS acTo', 'acTo.id', '=', 'sacct.to_account_id')
                ->Join('savings_accounts AS acFrom', 'acFrom.id', '=', 'sacct.from_account_id')
                //   ->Join('savings_products AS sp', 'sp.id', '=', 'acTo.savings_product_id')
                // ->Join('savings_products AS sp2', 'sp2.id', '=', 'acFrom.savings_product_id')
                ->where('trs.member_id', $query->member_id)
                ->where('trs.type', 'Transfer')
                // ->orWhere('savings_account_transfers_id', $query->id)
                ->select([
                    'trs.amount',
                    'trs.charge_amount as charge',
                    'trs.reference',
                    'trs.is_reversed as reversed',
                    'trs.type AS transaction_type',
                    'stf.name AS created_by',
                    'trs.is_reversible as reversible',
                    'trs.code AS transaction_code',
                    'trs.code AS transaction_code',
                    'trs.transaction_date AS completed_at',
                    'trs.created_at AS created_at',
                    'acTo.code AS transfer_to_product',
                    'acFrom.code AS transfer_form_product',
                    //   'sp.name AS transfer_to_product',
                    // 'sp2.name AS transfer_from_product',
                    // DB::raw("JSON_OBJECT('branch',brch.name,'code',brch.code,'address',brch.address,'phone',brch.phone) AS branch_details "),
                ])->get();
            // if (! empty($getTransaction)) {
            //     $leng = count($getTransaction);
            //     for ($i = 0; $i < $leng; $i++) {
            //         $getTransaction[$i]->branch_details = $this->isJSONToArray($getTransaction[$i]->branch_details);
            //     }
            // }
            $query->transfer = $transfetList;
            $query->transactionList = $getTransaction;

            return $query;
        });
    }

    public function printMemberAccount()
    {
        return $this->TryCatch(function () {
            $req = request();
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('savings_accounts as ac')
                ->Join('members AS mb', 'ac.member_id', '=', 'mb.id')
                ->Join('savings_products AS sp', 'sp.id', '=', 'ac.savings_product_id')
                ->select([
                    ...$this->savingsAccountDbFields,
                    'sp.name As product',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                ]);
            if ($staus) {
                $query->whereIn('ac.status', $staus);
            }
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [...$this->savingsAccountDbFields, 'sp.name', 'mb.name']);
            }

            $data = $query
                // ->get();
                ->whereRaw('ac.branch_id=?', $req->branch_id)

                ->whereNull('ac.deleted_at')->orderBy('id', 'DESC')->paginate($this->perpage());
            $data = $data->items();

            return view('saving-account.print-member-saving-account', array_merge(['data' => $data, 'paperSize' => $req['scale']], $this->brandingViewData()))->render();
        });
    }

    public function memberAccountList()
    {
        return $this->TryCatch(function () {
            $req = request();
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('savings_accounts as ac')
                ->Join('members AS mb', 'ac.member_id', '=', 'mb.id')
                ->Join('savings_products AS sp', 'sp.id', '=', 'ac.savings_product_id')
                ->select([
                    ...$this->savingsAccountDbFields,
                    'sp.name As product',
                    "ac.payment_mod_account_id as payment_mod",
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) As member_name"),
                ]);
            if ($staus) {
                $query->whereIn('ac.status', $staus);
            }
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query(
                    $query,
                    $req['search_keyword'],
                    [
                        ...$this->savingsAccountDbFields,
                        'sp.name',
                        'mb.name As member_name',
                        'mb.code',
                        'mb.phone'
                    ],
                    filterable: [
                        "member_name" => 'mb.name',
                        "product" => 'sp.name'

                    ]
                );
            }

            // ->get();
            return $query
                ->whereRaw('ac.branch_id=?', [$req->branch_id])

                ->whereNull('ac.deleted_at')
                ->whereNull('mb.deleted_at')
                ->orderBy('id', 'DESC')
                ->paginate($this->perpage());
        });
    }

    public function groupAccountTileAnalysis($branchId = null)
    {
        $query = DB::table('savings_groups')
            ->select([
                DB::raw('COUNT(*) as total_groups'),
                DB::raw("SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_groups"),
                // DB::raw("SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_groups"),
                // DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_groups"),
            ])
            ->whereNull('deleted_at');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->first();
    }

    public function groupAccountList()
    {

        $req = request();

        return $this->TryCatch(function () use ($req) {
            $brach = $req->branch_id;
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('savings_groups as gac')
                ->Join('staff AS sf', 'sf.id', '=', 'gac.created_by')
                ->LeftJoin('savings_group_members AS sgm', 'sgm.savings_group_id', '=', 'gac.id')
                ->LeftJoin('members AS mb', 'mb.id', '=', 'sgm.member_id')
                ->select([
                    ...$this->grSavingsAccountDbFields,
                    'sf.name As created_by',
                    DB::raw('COUNT(sgm.id) AS total_in_group'),
                ]);
            if ($staus != null) {
                $query->whereIn('gac.status', $staus);
            }
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [...$this->grSavingsAccountDbFields, 'mb.name', 'mb.code', 'sf.name'], [
                    'created_at' => 'gac.created_at',
                    'dcreated' => 'gac.date_created',

                ]);
            }
            $query = $query->groupBy('gac.id', 'sf.id')
                ->whereRaw('gac.branch_id=?', $brach)
                ->whereNull('gac.deleted_at')->orderBy('id', 'DESC')->paginate($this->perpage());

            return [
                ...$query->toArray(),
                'total_analysis' => $this->groupAccountTileAnalysis($brach),
            ];
        });
    }

    public function groupAccountDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $groupId = $req['id'];
            $query = DB::table('savings_groups as gac')
                ->Join('staff AS sf', 'sf.id', '=', 'gac.created_by')
                ->select([
                    ...$this->grSavingsAccountDbFields,
                    'sf.name As created_by',
                    'gac.description as desc',
                    'gac.total_balance as blc',
                    'gac.other_contact_phone as phone2',
                ]);

            $query = $query->whereRaw('gac.id=?', [$groupId])->first();
            $getMembers = [];
            // / dont do what you  thinking
            DB::table('savings_group_members as sgm')
                ->join('members AS mb', 'sgm.member_id', '=', 'mb.id')
                ->select([
                    'mb.phone as phone',
                    'mb.code as member_code',
                    'sgm.code as member_group_code',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) as member_name"),
                    'sgm.created_at AS created_at',
                ])
                ->whereRaw('sgm.savings_group_id=?', [$groupId])
                ->orderBy('sgm.id')
                ->chunk(100, function ($rows) use (&$getMembers, $query) {
                    foreach ($rows as $row) {
                        $row->product = $query?->group_name ?? null;
                        $getMembers[] = $row;
                    }
                });
            $query->memebers = $getMembers;
            $query->total_members = count($getMembers);

            return $query;
        });
    }

    public function editMemberAccountDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $groupId = $req['id'];
            $query = DB::table('savings_accounts as acc')
                ->select([
                    'id',
                    'consider_min_balance',
                    'initial_deposit',
                    'account_opening_balance as opening_balance',
                    'savings_product_id',
                    'account_type',
                    'member_id',
                ])->whereRaw('acc.id=?', [$groupId])->first();

            return $query;
        });
    }

    public function getGroupMemberWithRunningLoans()
    {
        return $this->TryCatch(function () {

            $req = request();
            $groupId = (int) ($req['group_id']) ?? null;  // dont do what you  thinking  we save=ing Ram
            $status = isset($req['status']) && $req['status'] !== 'all' ? [$req['status']] : null;

            return DB::table('loan_applications as la')
                ->when($groupId, function ($query) use ($groupId) {
                    $query->whereRaw('sgm.savings_group_id=?', [$groupId]);
                })
                ->when($status, function ($query) use ($status) {
                    $query->whereIn('ln.status', $status);
                })
                // savings_group_members
                ->whereNull('ln.deleted_at')
                ->Leftjoin('loans as ln', 'la.id', '=', 'ln.loan_application_id')
                // ->join('loan_applications as la', 'la.id', '=', 'ln.loan_application_id')
                ->join('members as mb', 'mb.id', '=', 'la.member_id')
                ->join('savings_group_members as sgm', 'sgm.member_id', '=', 'mb.id')
                ->join('savings_accounts as sact', 'sact.member_id', '=', 'mb.id')
                ->when(! empty($req['search_keyword']), function ($query) use ($req) {
                    $this->dynamic_search_db_query(
                        $query,
                        $req['search_keyword'],
                        [
                            'sact.code as account_code',
                            'ln.loan_no',
                            'mb.name',
                            'mb.code',
                            'mb.phone',
                            'ln.status',
                            'ln.created_at',
                        ]
                    );
                })
                ->select([
                    DB::raw('IFNULL(la.status,ln.status) as loan_status'),
                    // 'ln.status as loan_status',
                    'sact.code as account_code',
                    'ln.id as id',
                    'la.application_no as application_code',
                    'la.id as application_id',
                    'ln.loan_no as code',
                    'ln.balance as blc',
                    DB::raw("CONCAT(IFNULL(mb.salutation,''), ' ', mb.name) as member_name"),
                    'mb.id as member_id',
                    'mb.code as member_code',
                    'mb.phone as member_phone',
                    'ln.created_at as created_at',
                    DB::raw('COALESCE(ln.balance, 0) as total_loan_balance'),
                    'ln.id As loan_id',
                ])

                ->orderBy('ln.id', 'DESC')
                ->paginate($this->perpage());
        });
    }

    public function groupAccountProfileCompleteness()
    {
        $req = request();

        $groupId = $req['group_id'];
        $groupDetails = $this->TryCatch(function () use ($groupId) {
            $query = DB::table('savings_groups as gac')
                ->select([
                    ...$this->grSavingsAccountDbFields,
                    'gac.description as desc',
                    DB::raw("CONCAT('" . htmlspecialchars(config('app.url')) . "','/', gac.image_path) AS group_image"),

                    // 'gac.total_balance as blc',
                    DB::raw('(SELECT SUM(sgac.balance) FROM group_savings_accounts AS sgac 
                        where sgac.savings_group_id=gac.id) as available_balance'),
                    'gac.other_contact_phone as phone2',
                ]);
            $query = $query->where('gac.id', $groupId)->first();

            return $query;
        });

        $groupMmebers = $this->TryCatch(function () use ($groupId, $groupDetails) {
            $getMembers = [];
            // / dont do what you  thinking
            $loanSummary = DB::table('loan_applications as la')
                ->join('loans as l', 'la.id', '=', 'l.loan_application_id')
                ->where('l.status', '!=', 'closed')
                ->selectRaw('
        la.member_id,
        SUM(l.balance) as total_loan_balance,
        MAX(l.id) as loan_id
    ')
                ->groupBy('la.member_id');

            $query = DB::table('savings_group_members as sgm')
                ->whereRaw('sgm.savings_group_id=?', [$groupId])
                ->join('members AS mb', 'sgm.member_id', '=', 'mb.id')
                ->leftJoinSub($loanSummary, 'ls', function ($join) {
                    $join->on('mb.id', '=', 'ls.member_id');
                })
                ->select([
                    'mb.id',
                    'mb.phone as phone',
                    'mb.code as member_code',
                    'mb.status as member_status',
                    'sgm.code as member_group_code',
                    'sgm.balance as total_amount_deposited',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mb.name) as member_name"),
                    'sgm.created_at AS created_at',
                    DB::raw('COALESCE(ls.total_loan_balance, 0) as total_loan_balance'),
                    'ls.loan_id',
                ])
                ->whereRaw('sgm.savings_group_id=?', [$groupId])
                ->orderBy('sgm.id')
                ->chunk(100, function ($rows) use (&$getMembers) {
                    foreach ($rows as $row) {
                        $getMembers[] = $row;
                    }
                });
            if (isset($groupDetails)) {
                $groupDetails->total_members = count($getMembers);
            }

            return $getMembers;
        });
        $groupAccounts = $this->TryCatch(function () use ($groupId) {
            $query = DB::table('group_savings_accounts as sgac')
                ->join('savings_products AS sp', 'sp.id', '=', 'sgac.savings_product_id')
                ->where('sgac.savings_group_id', $groupId)
                ->select([
                    'sgac.id AS id',
                    'sgac.code AS code',
                    'sp.name AS product',
                    //  'sgac.account_type AS account_type',
                    'sgac.balance AS blc',
                    'sgac.initial_deposit as initial_deposit',
                    'sgac.opening_balance as opening_balance',
                    'sgac.status AS status',
                    'sgac.created_at AS created_at',
                ])

                ->orderBy('sgac.id', 'DESC')
                ->get();

            return $query;
        });

        return [
            'group_details' => $groupDetails,
            'group_members' => $groupMmebers,
            'group_accounts' => $this->collectGroupSavingAccountList(),
        ];
    }

    public function collectGroupSavingAccountList()
    {
        return $this->TryCatch(function () {
            $groupId = request('group_id');
            $query = DB::table('group_savings_accounts as sgac')
                ->join('savings_products AS sp', 'sp.id', '=', 'sgac.savings_product_id')
                ->where('sgac.savings_group_id', $groupId)
                ->select([
                    'sgac.id AS id',
                    'sgac.code AS code',
                    'sgac.code AS name',
                    'sp.name AS product',
                    //  'sgac.account_type AS account_type',
                    'sgac.balance AS blc',
                    'sgac.payment_mod_account_id AS payment_mod',
                    'sgac.initial_deposit as initial_deposit',
                    'sgac.opening_balance as opening_balance',
                    'sgac.status AS status',
                    'sgac.created_at AS created_at',
                ])

                ->orderBy('sgac.id', 'DESC')
                ->get();

            return $query;
        });
    }

    public function addMemberGroupDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
            'group_id' => ['required', 'exists:savings_groups,id'],
        ]);

        return $this->TryCatch(function () use ($req) {
            // dont do what you  thinking here
            $getThemebersInTheGroupIds = DB::table('savings_group_members as sgm')->where('sgm.savings_group_id', $req['group_id'])->pluck('sgm.member_id');

            $query = DB::table('members as mb')
                ->whereNotIn('mb.id', $getThemebersInTheGroupIds)
                ->select(['mb.id', DB::raw("CONCAT( IFNULL(salutation,''), ' ', mb.name )as name")])
                ->whereNull('mb.deleted_at');

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mb.name']);
            }

            return $query->orderBy('mb.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function groupsDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('savings_groups as sg')
                ->join('group_savings_accounts as sgac', 'sg.id', '=', 'sgac.savings_group_id')
                ->select([
                    'sg.id',
                    'sgac.code AS account_code',
                    'sgac.id AS account_id',
                    DB::raw("CONCAT( IFNULL(sg.name,'')) as name"),
                    // DB::raw("CONCAT( IFNULL(sg.name,''), ' - ', IFNULL(sg.code,'')) as name"),
                    DB::raw('SUM(sgac.balance) as balance'),
                ]);

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['rl.id AS id', 'name']);
            }
            $query = $query
                ->groupby('sg.id', 'sgac.code', 'sgac.id');

            return $query->whereNull('sg.deleted_at')
                ->orderBy('sg.id', 'DESC')->paginate($this->perpage());

            // return $this->TryCatch(function () {
            //     return $this->dropDownList('savings_groups');
            // });
        });
    }

    public function savingsAccountsDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('savings_accounts as sa')
                ->join('members as mb', 'sa.member_id', '=', 'mb.id')
                ->select([
                    'sa.id',
                    DB::raw("CONCAT(IFNULL(sa.code,''), ' - ', IFNULL(mb.salutation,''), ' ', IFNULL(mb.name,''),'-(',IFNULL(sa.balance,''), '-', IFNULL(sa.account_type,'')) as name"),
                ])
                ->whereNull('sa.deleted_at');
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['sa.id AS id', 'mb.name', 'mb.code', 'sa.account_type', 'sa.code']);
            }

            return $query
                ->whereRaw('sa.branch_id=?', $req->branch_id)
                ->orderByDesc('sa.id')
                ->paginate(20);
        });
    }

    private function brandingViewData(): array
    {
        $branding = SaccoBranding::current();
        $logoDataUri = null;
        if ($branding->logo_path) {
            $logoAbsPath = Storage::disk('public')->path($branding->logo_path);
            if (file_exists($logoAbsPath)) {
                $ext = strtolower(pathinfo($logoAbsPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoAbsPath));
            }
        }

        return [
            'saccoName' => $branding->sacco_name ?? 'Your Company Name',
            'saccoTagline' => $branding->tagline,
            'logoDataUri' => $logoDataUri,
        ];
    }
}
