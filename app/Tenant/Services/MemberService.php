<?php

namespace App\Tenant\Services;

use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;
use Illuminate\Support\Facades\DB;

class MemberService extends MemberUpdateOrCreateService
{
    protected $memebrDbFields = [
        'mbs.id As id',
        'mbs.code As memeber_code',
        'mbs.member_type As member_type',
        'mbs.name As full_name',
        // 'mbs.profile_path As profile',
        'mbs.national_id_number As NIN',
        'mbs.joined_date As joined_date',
        'mbs.email As email',
        'mbs.gender As sex',
        'mbs.phone As primary_contact',
        'mbs.other_contact As other_contacts',
        'mbs.marital_status As marital_status',
        'mbs.created_at As created_at',
    ];

    protected array $searchFields = [
        'Host Name' => 'domain',
        'Database' => 'database_name',
        'sacco name' => 'name',
        'Sacco Domain' => 'subdomain',
        'status' => 'status',
        'created Date' => 'created_at',
    ];

    public function membersStatements()
    {
        $filters = request()->all()['filters'] ?? null;
        // $statements = DB::table('')->whereIn();

    }

    public function membersList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('members AS mbs')
                ->select([
                    DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"),
                    'mbs.dormant_date As dormant_date',
                    DB::raw("CASE
                        WHEN mbs.profile_picture IS NOT NULL AND mbs.profile_picture != '' THEN CONCAT('/storage/', mbs.profile_picture)
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' AND mbs.profile_path LIKE '/storage/%' THEN mbs.profile_path
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' AND mbs.profile_path LIKE 'storage/%' THEN CONCAT('/', mbs.profile_path)
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' THEN CONCAT('/storage/', mbs.profile_path)
                        ELSE NULL
                    END AS profile"),
                    ...$this->memebrDbFields,
                ]);
            if ($req->has('search_keyword')) {

                $query = $this->dynamic_search_db_query($query, $req->search_keyword, [
                    'mbs.name',
                    'member_type',
                    'mbs.code',
                    ...$this->memebrDbFields,
                ], filterable: [
                    'member_type' => 'mbs.member_type',
                    'joined_date' => 'mbs.joined_date',
                ]);
            }

            if (! empty($req->branch_id)) {
                $query->where('mbs.branch_id', $req->branch_id);
            }
            if ($staus != null) {
                if (in_array('dormant', $staus)) {
                    $query->whereNotNull('mbs.dormant_date');
                } else {
                    $query->whereIn('mbs.status', $staus);
                }
            }
            if (! in_array('dormant', $staus ?? [])) {
                $query->whereNull('mbs.dormant_date');
            }

            return $query->orderBy('created_at', 'DESC')->paginate($this->perpage());
        });
    }

    public function profileCompleteness()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $runingBlanc = DB::table('savings_accounts')->where('member_id', '=', $req->member_id)->whereNull('deleted_at')->get()->sum('balance');
            $MemberQueryDetails = DB::table('members AS mbs')
                ->whereRaw('mbs.id=?', [$req->member_id])
                ->leftJoin('shares AS shr', 'shr.member_id', '=', 'mbs.id')
                ->leftJoin('staff AS stf', 'stf.id', '=', 'mbs.created_by')
                ->select([
                    // DB::raw("CONCAT(IFNULL(salutation,''), ':', mbs.name) As salutation_name"),
                    ...$this->memebrDbFields,
                    DB::raw("CASE
                        WHEN mbs.profile_picture IS NOT NULL AND mbs.profile_picture != '' THEN CONCAT('/storage/', mbs.profile_picture)
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' AND mbs.profile_path LIKE '/storage/%' THEN mbs.profile_path
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' AND mbs.profile_path LIKE 'storage/%' THEN CONCAT('/', mbs.profile_path)
                        WHEN mbs.profile_path IS NOT NULL AND mbs.profile_path != '' THEN CONCAT('/storage/', mbs.profile_path)
                        ELSE NULL
                    END AS profile"),

                    'mbs.initial_deposit AS initial_deposit',
                    'shr.total_value AS total_shares_value',
                    // 'shr.share_no AS share_no',
                    // 'shr.share_value AS share_value',
                    //
                    DB::raw("IF(mbs.status !='Active', '0',shr.share_no) as share_value"),
                    // 'shr.share_value AS share_value',
                    'mbs.created_at AS created_at',
                    'stf.name AS created_by_name',
                    'mbs.updated_at AS updated_at',
                    'mbs.salutation As salutation',
                    'mbs.gender As gender',
                    'mbs.other_contact As o_contact',
                    'stf.name As created_by',
                    'mbs.mobile_money_number As MM_number',
                    'mbs.dob AS dob', // full
                    'mbs.joined_date AS joined_date',
                    'mbs.address As address',
                    'mbs.next_of_kin As nokin',
                    'mbs.next_of_kin_contact As next_contact',
                    'mbs.initial_deposit As inital_deposit',
                    'mbs.is_shareholder As shareholder',
                    'mbs.opening_balance As opb',
                    'mbs.status As status',
                    'mbs.nationality As from',
                    DB::raw('(select name from members where id = mbs.referred_by )as referred_by'),
                    // DB::raw('(select name from members where id = mbs.created_by) as created_by'),
                ])
                ->first();

            $MemberQueryDetails->total_balance = $runingBlanc;

            $OtherHelpers = new OtherHelpers;

            return [
                'member_details' => $MemberQueryDetails,
                'member_accounts' => $OtherHelpers->getAllmemberAccounts(['member_id' => $req->member_id, 'branch_id' => $req->branch_id]),

            ];
        });
    }

    public function membersDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('members AS mbs')->select([
                DB::raw("CONCAT(IFNULL(salutation,''), ':', name) As salutation_name"),
                ...$this->memebrDbFields,
                'initial_deposit AS initial_deposit',
                DB::raw("IF(mbs.profile_picture IS NOT NULL AND mbs.profile_picture != '', CONCAT('/storage/', mbs.profile_picture), IF(mbs.profile_path IS NOT NULL AND mbs.profile_path != '', CONCAT('/storage/', mbs.profile_path), NULL)) AS profile"),
                'created_at AS created_at',
                'updated_at AS updated_at',
                'salutation As salutation',
                'gender As gender',
                'other_contact As o_contact',
                'mobile_money_number As MM_number',
                'dob AS dob',
                'address As address',
                'next_of_kin As nokin',
                'next_of_kin_contact As next_contact',
                'initial_deposit As inital_deposit',
                'is_shareholder As shareholder',
                'opening_balance As opb',
                'status As status',
                'nationality As from ',
                DB::raw('(select name from members where id = mbs.referred_by )as referred_by'),
                DB::raw('(select name from members where id = mbs.created_by) as created_by'),
                // DB::raw('(select name from roles where id = mbs.role_id) as role_name'),
            ]);

            return $query

                ->whereRaw('mbs.id=?', [$req->id])->first();
        });
    }

    public function editMemberDetails()
    {

        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('members AS mbs')
                ->leftJoin('shares AS shr', 'shr.member_id', '=', 'mbs.id')
                ->select([
                    ...$this->memebrDbFields,
                    'initial_deposit AS initial_deposit',
                    'salutation As salutation',
                    'gender As gender',
                    'other_contact As o_contact',
                    'mobile_money_number As MM_number',
                    'shr.total_value As total_shares_value',
                    'shr.share_no As share_no',
                    'dob AS dob',
                    'nationality AS from',
                    'profile_path AS profile',
                    'address As address',
                    'next_of_kin As nokin',
                    'next_of_kin_contact As next_contact',
                    'initial_deposit As inital_deposit',
                    'is_shareholder As shareholder',
                    'opening_balance As opb',
                    'status As status',
                    'nationality As from',
                    'joined_date As joined_date',
                    DB::raw('(select name from members where id = mbs.referred_by )as referred_by'),
                    DB::raw('(select name from members where id = mbs.created_by) as created_by'),
                    // DB::raw('(select name from roles where id = mbs.role_id) as role_name'),
                ]);

            return $query

                ->whereRaw('mbs.id=?', [$req->id])->first();
        });
    }

    public function GeneralProductChargesDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
            'type' => ['required', 'string', 'in:onboarding'],
            'id' => ['required', 'integer', 'exists:tenant.savings_products,id'],
        ]);

        return $this->TryCatch(function () use ($req) {
            // 'onboarding' is the only consumer today (members Create.vue);
            // it maps to general_charges.application='on_registration' linked via
            // the savings_product_charges pivot with type='registration'.
            $pivotType = 'registration';
            $application = 'on_registration';

            $query = DB::table('savings_product_charges AS spc')
                ->join('general_charges AS gchrg', 'gchrg.id', '=', 'spc.general_charge_id')
                ->where('spc.savings_product_id', $req->id)
                // ->where('spc.type', $pivotType) // newton explain why this is here
                ->where('gchrg.application', $application)
                ->where('gchrg.is_active', 1)
                ->whereNull('gchrg.deleted_at')
                ->select([
                    'gchrg.id',
                    'gchrg.name as name',
                    DB::raw('SUM(gchrg.amount) as charge_amount'),
                ])
                ->groupBy('gchrg.id', 'gchrg.name');

            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    'gchrg.id AS id', 'gchrg.name', 'gchrg.code',
                ]);
            }

            return $query->orderBy('gchrg.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function MemberSavingAccountsDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
            'member_id' => ['required', 'exists:members,id'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('savings_accounts AS sacct')
                ->where('sacct.member_id', $req->member_id)
                ->select([
                    'sacct.id',
                    'sacct.code As name',
                    DB::raw('SUM(sacct.balance) as balance'),
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['sacct.id AS id', 'sacct.name', 'sacct.code']);
            }
            $query = $query
                ->groupby('sacct.id');

            return $query->whereNull('sacct.deleted_at')
                ->orderBy('sacct.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function savingsProducts()
    {
        return $this->dropDownList('savings_products');
    }

    public function productCharges()
    {
        $caller = new ProductChargesservice;

        return $caller->productCharges();
    }

    public function seleceTableKeysToUseOnTemplate()
    {
        // ID	BRANCH_ID	DOB	MEMBER_NUMBER	ACCOUNT_NUMBER	SALUTATION	IS_SHAREHOLDER	NEXT_OF_KIN_CONTACT	MARITAL_STATUS	NATIONALITY	MOBILE_MONEY_NUMBER	GENDER	PHONE	MEMBER_TYPE	NAME	NEXT_OF_KIN_CONTACT_COUNTRY	OPENING_BALANCE	SHARES_QUANTITY	EMAIL
        //
        return [
            // 'is_external_member' => 'external member',
            // 'branch_id' => 'branch id',
            'salutation' => 'salutation',
            'name' => 'name',
            'gender' => 'gender',
            'initial_deposit' => 'initial deposit',
            'opening_balance' => 'opening balance',
            'phone' => 'phone',
            'member_number' => 'member number',
            'account_number' => 'account number',
            'phone_country' => 'phone country',
            'other_contact' => 'other contact',
            'dob' => 'date of birth',
            'email' => 'email',
            'product_code' => 'account product code',

            'other_contact_country' => 'other contact country',
            'mobile_money_number' => 'mobile money number',
            'mobile_money_country' => 'mobile money country',
            'address' => 'address',
            'next_of_kin' => 'next of kin',
            'next_of_kin_contact' => 'next of kin contact',
            'next_of_kin_contact_country' => 'next of kin contact country',
            'shares_quantity' => 'shares quantity',
            'marital_status' => 'marital status',
            'nationality' => 'nationality',
            'joined_date' => 'joined date',
            'national_id_number' => 'national id number',

        ];
    }

    public function printMemberList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('members AS mbs')->select([DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"), ...$this->memebrDbFields]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req->search_keyword, $this->memebrDbFields);
            }
            if (! empty($req->branch_id)) {
                $query->where('mbs.branch_id', $req->branch_id);
            }
            if ($staus != null) {
                $query->whereIn('mbs.status', $staus);
            }
            $data = $query
                ->whereNull('deleted_at')->orderBy('created_at', 'DESC')->paginate($this->perpage());
            $data = $data->items();

            return view('members.print-member-list', ['data' => $data, 'paperSize' => $req['scale']])->render();
        });
    }

    public function MemberAndAccountBalanceDropDownList()
    {
        $req = request();
        request()->validate([
            'search_keyword' => ['nullable', 'string', 'max:20'],
        ]);

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('members AS mbs')
                ->where('mbs.status', 'active')
                ->join('savings_accounts AS sacct', 'sacct.member_id', '=', 'mbs.id')
                ->select([
                    'sacct.id',
                    'mbs.id as member_id',
                    'sacct.id as account_id',
                    'sacct.code As account_code',
                    DB::raw("CONCAT( IFNULL(mbs.name,'')) as name"),
                    DB::raw('SUM(sacct.balance) as balance'),
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mbs.id AS id', 'mbs.name', 'mbs.code', 'sacct.code']);
            }
            $query = $query
                ->groupby('sacct.id');

            return $query->whereNull('sacct.deleted_at')
                ->orderBy('sacct.id', 'DESC')->paginate($this->perpage());
        });
    }

    public function MemberDropDownList()
    {
        return $this->dropDownList('members');
    }

    public function MemberShareNoDropDownList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query =
                DB::table('shares AS shr')
                // ->Leftjoin('members AS mbs', 'shr.member_id', '=', 'mbs.id')// just for debuging
                    ->join('members AS mbs', 'shr.member_id', '=', 'mbs.id')
                    ->select([
                        'mbs.id',
                        DB::raw("CONCAT( IFNULL(mbs.name,'')) as name"),
                        DB::raw('(shr.share_no) as total_shares'),
                        DB::raw('mbs.id AS member_id'),
                    ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], ['mbs.id AS id', 'name']);
            }

            return $query->groupby('shr.id')->whereNull('shr.deleted_at')
                ->orderBy('shr.id', 'DESC')->paginate($this->perpage());
        });

        // return $this->dropDownList('members');
    }

    public function downloadMemberImportTemplate()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $query = DB::table('members AS mbs')
                ->Join('savings_accounts As sact', 'sact.member_id', '=', 'mbs.id')
                ->select([
                    'sact.id As id',
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', name) As salutation_name"),
                    'mbs.code As memeber_code',
                    'sact.code As account_number',
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req->search_keyword, $this->memebrDbFields);
            }
            if (! empty($req->branch_id)) {
                $query->where('mbs.branch_id', $req->branch_id);
            }

            return $query
                ->whereNull('mbs.deleted_at')->orderBy('mbs.created_at', 'DESC')->paginate($this->perpage());
        });
    }
}
