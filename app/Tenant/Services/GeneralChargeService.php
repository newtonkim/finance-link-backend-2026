<?php

namespace App\Tenant\Services;

use Illuminate\Support\Facades\DB;

class GeneralChargeService extends GeneralChargeUpdateOrCreateService
{
    protected $savingsAccountDbFields = [
        'gc.id As id',
        'gc.code AS charge_code',
        'gc.amount AS charge_amount',
        'gc.name AS charge_name',
        'gc.application AS application',
        'gc.where_to_apply AS charge_applys',
        'gc.created_at AS created_at',
    ];

    public function generalChargeList()
    {
        return $this->TryCatch(function () {
            $req = request();
            $query = DB::table('general_charges as gc')
                ->leftJoin('savings_product_charges AS spc', 'spc.general_charge_id', '=', 'gc.id')
                ->leftJoin('savings_products AS sp', 'sp.id', '=', 'spc.savings_product_id')
                ->select([
                    ...$this->savingsAccountDbFields,
                    DB::raw("GROUP_CONCAT(DISTINCT sp.name SEPARATOR ', ') as products"),
                    'gc.charge_type as charge_type',
                    'gc.is_reversible as Reversible',
                    'gc.interval_type as interval_type',
                    'gc.is_active as is_active',
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    ...$this->savingsAccountDbFields,
                    'gc.id',
                    'gc.code',
                    'gc.name',
                    'sp.name',
                    'gc.where_to_apply',
                    'gc.is_revenue',
                    'gc.created_at',
                ]);
            }

            return $query
                ->where('gc.branch_id', $req->branch_id)
                ->whereNull('gc.deleted_at')
                ->groupBy('gc.id')
                ->orderBy('id', 'DESC')->paginate($this->perpage());
        });
    }

    public function generalChargeDetail()
    {

        return $this->TryCatch(function () {
            $req = request();
            $query = DB::table('general_charges as gc')
                ->leftJoin('savings_product_charges AS spc', 'spc.general_charge_id', '=', 'gc.id')
                ->leftJoin('savings_products AS sp', 'sp.id', '=', 'spc.savings_product_id')
                ->select([
                    ...$this->savingsAccountDbFields,
                    DB::raw("GROUP_CONCAT(DISTINCT sp.name SEPARATOR ', ') as products"),
                    'gc.charge_type as charge_type',
                    'gc.interval_type as interval_type',
                ]);
            if ($req->has('search_keyword')) {
                $query = $this->dynamic_search_db_query($query, $req['search_keyword'], [
                    ...$this->savingsAccountDbFields,
                    'gc.id',
                    'gc.code',
                    'gc.name',
                    'sp.name',
                    'gc.where_to_apply',
                    'gc.is_revenue',
                    'gc.created_at',
                ]);
            }

            return $query
                ->where('gc.id', $req->id)
                ->groupBy('gc.id')
                ->first();
        });
    }
}
