<?php

namespace App\Tenant\Services;

use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use Illuminate\Support\Facades\DB;

class SharesService extends ShareUpdateOrCreateService
{
    protected $shareDbFields = [
        'shr.id As id',
        'shr.code As share_code',

        'shr.share_no As share_no',
        'shr.share_value As share_value',
        'shr.total_value As total_value',
        'shr.purchased_at As purchased_at',

        'shr.created_at As created_at',
    ];

    public function chargeShareTransaction()
    {
        $caller = new ProductChargesservice;
        $req = request();

        return $caller->shareTransactionCharges([
            'shares' => $req->shares,
            'type' => $req->type,
        ]);
    }

    public function printFullshareHolderCetificate()
    {
        $req = request("value");

        return $this->TryCatch(function () use ($req) {
            $colmmns = [
                'mbs.id As member_id',
                'mbs.gender As sex',
                'mbs.phone As primary_contact',
                'mbs.other_contact As other_contacts',

            ];
            $query = DB::table('shares AS shr')
                ->where('shr.id', $req['id'])
                ->join('members AS mbs', 'mbs.id', '=', 'shr.member_id')
                ->select([
                    DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"),
                    ...$this->shareDbFields,
                    ...$colmmns,
                ])
                ->first();

            return view('shares.share-certificate', ['data' => $query,'fullcertificate' => 'Full Certificate'])->render();
        });
    }
    public function printsahreHolderCetificate()
    {
        $req = request("value");

        return $this->TryCatch(function () use ($req) {
            $colmmns = [
                'mbs.code As member_code',
                'mbs.id As member_id',
                'mbs.gender As sex',
                'mbs.phone As primary_contact',
                'mbs.other_contact As other_contacts',
                "meta_details_before_transaction",
                "trs.created_at as purchased_at",
                "trs.code as share_code",
                'trs.id as id',
            ];
    
           $query = DB::table('transactions AS trs')
                ->where('trs.member_id', $req['member_id'])
                ->whereIn('trs.account_type', ['share transfer', 'share purchase'])
                ->join('members AS mbs', 'mbs.id', '=', 'trs.member_id')
                ->select([
                    DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"),
                    ...$colmmns,
                    ])
                    ->orderBy('trs.id', 'desc')
                ->first();

            if (isset($query->meta_details_before_transaction)) {
                $shareTableDetails = json_decode($query->meta_details_before_transaction, true);
                $query->share_no = $shareTableDetails['transacted_share_points'] ?? 0;;
            }

            return view('shares.share-certificate', ['data' => $query,'fullcertificate' => 'Partial Certificate'])->render();
        });
    }
    public function shareHolderTransaction()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $colmmns = [
                'trs.is_reversed as reversed',
                'trs.id as id',
                'trs.member_id',
                'mbs.code as member_code',
                'trs.reference',
                'trs.amount',
                'trs.charge_amount',
                'trs.payment_mode',
                'trs.account_type as account_type',
                'narration',
                'trs.created_at',
            ];

            $callTenantAnalysis = DB::table('shares')->select([
                DB::raw('COUNT(*) as total_holder'),
                DB::raw('SUM(share_no) as total_share'),
                DB::raw('SUM(share_value) as total_share_value'),
            ])
                ->where('branch_id', $req->branch_id)
                ->first();

            $SettingCollection = DB::table('system_settings')
                ->whereIn('settings_name', ['sacco-share-price-value', 'sacco-share-maximum-share-numbers-one-should-have'])
                //    ->where('branch_id', $req->branch_id)
                ->get([
                    'settings_action',
                    'settings_name',
                ]);

            $sharePriceSetting = null;
            foreach ($SettingCollection as $key => $value) {
                if ($value->settings_name == 'sacco-share-price-value') {
                    $sharePriceSetting = $value;
                } elseif ($value->settings_name == 'sacco-share-maximum-share-numbers-one-should-have') {
                    $shareLimitSetting = $value;
                }
            }

            $priceNowForShare = $sharePriceSetting ? (json_decode($sharePriceSetting->settings_action, true)['action'] ?? 0) : 0;
            $limitNowForShare = $shareLimitSetting ? (json_decode($shareLimitSetting->settings_action, true)['action'] ?? 0) : 0;

            $capitalize = DB::table('share_capitalization')
                ->select([
                    DB::raw("'$limitNowForShare' as share_limit"),
                    'open_capital',
                    'opening_balance as balance',
                    'share_price',
                    'open_capital_points_walth_amount as wallet_amount',
                    'code as capitalization_code',
                ])
                ->where('branch_id', $req->branch_id)
                ->where('status', 'active')
                ->first();

            $query = DB::table('transactions AS trs')
                ->join('members AS mbs', 'mbs.id', '=', 'trs.member_id')
                ->where('mbs.branch_id', $req->branch_id)
                ->where('type', 'share-transaction')
                ->when($req->has('search_keyword'), function ($query) use ($req, $colmmns) {
                    $query = $this->dynamic_search_db_query($query, $req->search_keyword, [
                        'mbs.name',
                        ...$colmmns,
                    ], filterable: ['created_at' => 'trs.created_at']);
                })
                ->select([
                    DB::raw("CONCAT(IFNULL(salutation,''), ' ', mbs.name) As salutation_name"),
                    ...$colmmns,

                ]);
            $data = $query->orderBy('trs.id', 'DESC')->paginate($this->perpage());
            $payload = $data->toArray();

            return [
                ...$payload,
                'total_holder' => ($callTenantAnalysis->total_holder),
                'total_share' => $callTenantAnalysis->total_share,
                'total_share_value' => $callTenantAnalysis->total_share_value,
                'price_now_for_share' => $priceNowForShare,
                'share_limit' => $limitNowForShare,
                'share_capitalization' => $capitalize,
            ];
        });
    }

    public function shareSettingList() {}

    public function shareHolderList()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $colmmns = [
                'mbs.member_type As member_type',
                'mbs.id As member_id',
                'mbs.gender As sex',
                'mbs.phone As primary_contact',
                'mbs.other_contact As other_contacts',

            ];
            $query = DB::table('shares AS shr')
                ->where('shr.branch_id', $req->branch_id)
                ->whereNull('shr.deleted_at')->whereNull('mbs.deleted_at')
                ->join('members AS mbs', 'mbs.id', '=', 'shr.member_id')
                ->select([
                    DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"),
                    ...$this->shareDbFields,
                    ...$colmmns,
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req->search_keyword, [
                    ...$this->shareDbFields,
                    ...$colmmns,
                ], filterable: [
                    'shr.created_at',
                ]);
            }

            return $query->orderBy('shr.created_at', 'DESC')->paginate($this->perpage());
        });
    }

    public function shareHolderDetails()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            $staus = isset($req['status']) && $req['status'] != 'all' ? [$req['status']] : null;
            $query = DB::table('shares AS shr')
                ->join('members AS mbs', 'mbs.id', '=', 'shr.member_id')
                ->select([DB::raw("CONCAT(IFNULL(salutation,'-'), ':', name) As salutation_name"), ...$this->shareDbFields])->first();
        });
    }
}
