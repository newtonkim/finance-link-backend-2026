<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use Carbon\Carbon;

trait BuildsSavingsAccountFields
{
    public function groupNoneMembersCreateUorCFields($req)
    {
        return $this->removeAllNullValues([
            'member_type' => $req['member_type'] ?? null,
            'name' => $req['full_name'] ?? null,
            'salutation' => $req['salutation'] ?? null,
            'gender' => $req['gender'] ?? null,
            'phone' => $req['primary_contact'] ?? null,
            'other_contact' => $req['other_contacts'] ?? null,
            'email' => $req['email'] ?? null,
            'status' => $req['status'] ?? null,
            'national_id_number' => $req['national_id'] ?? null,
            'mobile_money_number' => $req['mobile_money_number'] ?? null,
            'marital_status' => $req['marital_status'] ?? null,
            'nationality' => $req['nationality'] ?? null,
            'address' => $req['address'] ?? null,
            'next_of_kin' => $req['next_of_kin'] ?? null,
            'next_of_kin_contact' => $req['next_of_kin_contact'] ?? null,
            'initial_deposit' => $req['inital_deposit'] ?? null,
            'joined_date' => $req['joined_date'] ?? null,
            'referred_by' => $req['referred_by'] ?? null,
            'opening_balance' => $req['opening_balance'] ?? null,
            'shares_quantity' => $req['shares_quantity'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
        ]);
    }

    public function groupAccountUorCFields($data)
    {
        return $this->removeAllNullValues(
            [
                'name' => $data['group_name'] ?? null,
                'date_created' => isset($data['dcreated']) ? Carbon::parse(trim($data['dcreated'], '"'))->toDateTimeString() : null,
                'location' => $data['address'] ?? null,
                'primary_contact_phone' => $data['phone1'] ?? null,
                'other_contact_phone' => $data['phone2'] ?? null,
                'description' => $data['group_description'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
            ]
        );
    }

    protected function transferUorCFields($data)
    {
        return $this->removeAllNullValues(
            [
                'code' => $data['code'] ?? null,
                'transaction_date' => $data['transfer_date'] ?? null,
                'from_account_id' => $data['from'] ?? null,
                'to_account_id' => $data['to'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'amount' => $data['amount'] ?? null,
                'description' => $data['norration'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
            ]
        );
    }

    protected function groupSavingsAccountUorCFields($data)
    {
        return $this->removeAllNullValues(
            [
                'savings_group_id' => $data['group_id'] ?? null,
                // 'group_account_id' => $data['account_code'] ?? null,
                'savings_product_id' => $data['product_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'opening_balance' => $data['opening_balance'] ?? null,
                'initial_deposit' => $data['initial_balance'] ?? null,
                'balance' => $data['amount'] ?? null,
                'is_new_account' => $data['new_account'] ?? null,
                'status' => $data['status'] ?? null,
                'payment_mod_account_id' => $data['payment_mode_id'] ?? null,
            ]
        );
    }
}
