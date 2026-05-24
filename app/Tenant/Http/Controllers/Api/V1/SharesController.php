<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Tenant\Services\SharesService;

class SharesController extends SharesService
{
    public function print_share_certificate()
    {
        return $this->Response([
            'data' => self::printsahreHolderCetificate(),
        ]);
    }
    public function print_full_share_certificate()
    {
        return $this->Response([
            'data' => self::printFullshareHolderCetificate(),
        ]);
    }
    public function sacco_shares_transfer()
    {
        return $this->Response([
            'data' => self::saccoSharesTransfer(),
        ]);
    }

    public function sacco_shares_withdrawal()
    {
        return $this->Response([
            'data' => self::saccoSharesWithdrawal(),
        ]);
    }

    public function sacco_shares_transaction_revert()
    {
        return $this->Response([
            'data' => self::saccoSharesTransactionRevert(),
        ]);
    }

    public function sacco_selling_shares()
    {
        return $this->Response([
            'data' => self::saccoSellingShares(),
        ]);
    }

    public function get_shares_holder_list()
    {
        return $this->Response([
            'data' => self::shareHolderList(),
        ]);
    }

    public function get_shares_holder_details()
    {
        return $this->Response([
            'data' => self::shareHolderDetails(),
        ]);
    }

    public function get_share_holder_transaction()
    {
        return $this->Response([
            'data' => self::shareHolderTransaction(),
        ]);
    }

    public function charge_share_transaction()
    {
        return $this->Response([
            'data' => self::chargeShareTransaction(),
        ]);
    }
}
