<?php

namespace App\Tenant\Services\MemebersSettingSevices;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;

class ProductChargesservice extends GlobalHelpers
{
    public function productCharges($data = null)
    {
        request()->validate([
            'amount' => ['required', 'numeric'],
            'type' => ['required', 'in:deposit,withdraw'],
            'product_id' => ['required', 'exists:savings_products,id'],
        ]);

        return $this->TryCatch(function () use ($data) {
            $req = $data ?? request();
            // $amount = $req['amount'];
            // $productId = $req['product_id'];
            //         $ch =DB::table('savings_product_charges AS spc')
            // ->where('savings_product_id', $productId)
            // ->where('minimum_amount', '<=', $amount)
            // ->where('maximum_amount', '>=', $amount)
            //  ->where('spc.type', $req['type'] == 'deposit' ? 'deposit' : 'withdraw')
            // ->first(['amount AS cost', 'id', 'charge_type']);

            $ch = DB::table('savings_product_charges as spc')
                ->whereRaw('? BETWEEN spc.minimum_amount AND spc.maximum_amount', [
                    $req['amount'],
                ])
                ->whereRaw('savings_product_id=?', [$req['product_id']])
                ->where('spc.type', $req['type'] == 'deposit' ? 'deposit' : 'withdraw')
                ->first(['amount AS cost', 'id', 'charge_type']);
            if (! empty($ch)) {
                if ($ch->charge_type == 'percentage') {
                    $ch->cost = $ch->cost * $req['amount'] / 100;

                    return $ch;
                } else {
                    return $ch;
                }
            }

            return (object) ['cost' => 0, 'id' => null, 'charge_type' => null];
        });
    }

    public function GeneralProductCharges($productId, $type)
    {
        return DB::table('savings_product_charges AS spc')
            ->join('general_charges AS gchrg', 'gchrg.id', '=', 'spc.general_charge_id')
            ->where('spc.savings_product_id', (int) $productId)
            ->where('gchrg.application', $type)
            ->where('gchrg.is_active', 1)
            ->whereNull('gchrg.deleted_at')
            ->select([
                'gchrg.id',
                'gchrg.credit_account_id',
                'gchrg.amount',
                'gchrg.name as name',
            ])
            ->groupBy('gchrg.id', 'gchrg.credit_account_id', 'gchrg.amount', 'gchrg.name')
            ->orderBy('gchrg.id', 'DESC')
            ->get();
    }

    public function shareTransactionCharges($data = [])
    {
        return $this->TryCatch(function () use ($data) {
            extract($data);
            $queAmount = $shares;
            $queType = $type;
            $ch = DB::table('share_transaction_charges as spc')
                ->whereRaw('? BETWEEN spc.minimum_shares AND spc.maximum_shares', [
                    $queAmount,
                ])
                ->where('spc.transaction_type', $queType)
                ->where('spc.status', 'active')
                ->first(['amount AS cost', 'id', 'charge_type']);
            if (! empty($ch)) {
                if ($ch->charge_type == 'percentage') {
                    $ch->cost = $ch->cost * $queAmount / 100;

                    return $ch;
                } else {
                    return $ch;
                }
            }

            return (object) ['cost' => 0, 'id' => null, 'charge_type' => null];
        });
    }
}
