<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\TenantSavingsAccountService;
use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;

trait ManagesGroupSavingsAccounts
{
    public function createGroupSavingAccount()
    {
        return $this->transaction(function () {
            return $this->tryCatch(function () {
                request()->validate([
                    'group_id' => ['required', 'string'],
                    'product_id' => ['required', 'string'],
                    'branch_id' => ['required', 'numeric'],
                    'opening_balance' => ['nullable', 'numeric', 'min:0'],
                    'initial_balance' => ['nullable', 'numeric', 'min:0'],
                    'new_account' => ['required', 'string'],
                    'status' => ['nullable', 'string', 'in:active,inactive,pending,closed'],
                ]);
                $req = request()->all();
                $new_account = $req['new_account'] == 1 ? true : false;
                $fields = $this->groupSavingsAccountUorCFields($req);
                $codeSequence = new CodeSequence;
                if (! isset($req['id'])) {
                    $fields['code'] = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_groups');
                }
                // set balance to opening_balance or initial_balance or amount, or 0 if none of them is provided
                $chargedAmount = 0;
                $deposit = 0;
                if ($new_account) {
                    $deposit = $fields['initial_deposit'];
                    $fields['balance'] = $deposit;
                } else {
                    $deposit = $fields['opening_balance'] ?? 0;
                    $fields['balance'] = $fields['opening_balance'] ?? 0;
                }
                $details = $this->UpdateOrCreateRecord('group_savings_accounts', $fields);
                if (isset($details->error)) {
                    throw new \Exception($details->error);
                }
                $code = $codeSequence->codeSequence(mt_rand(10000000, 99999999), tableTaget: 'transactions');

                $TransactionData = $this->transactionUorCFields([
                    'reference' => $code,
                    'gsaid' => $details->id,
                    'amount' => $deposit,
                    'payment_method' => $req['payment_method'] ?? 'cash',
                    'deposited_by' => $req['withdrawal_by'] ?? null,
                    'transaction_type' => 'withdrawal',
                    'charge_amount' => $chargedAmount,
                    'transaction_date' => now(),
                    'type' => 'withdrawal',
                    'narration' => $req['narration'] ?? null,
                ]);
                $this->UpdateOrCreateRecord('transactions', $TransactionData);
            });
        });
    }

    public function groupAccountCreate()
    {
        request()->validate([
            'members' => ['nullable', 'array'],
            'group_name' => ['nullable', 'string', 'max:90'],
            'dcreated' => ['nullable', 'string'],
            'location' => ['nullable', 'string'],
            'phone1' => ['nullable', 'string', 'max:17'],
            'phone2' => ['nullable', 'string', 'max:17'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $codeSequence = new CodeSequence;
                $OtherHelpers = new OtherHelpers;
                $req = request()->all();
                $gAcountData = $this->groupAccountUorCFields($req);
                if (! isset($req['id'])) {
                    $gAcountData['code'] = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_groups');
                }
                $groupLogoPath = $this->saveFile('group_logo', 'savings-group');
                if (! empty($groupLogoPath)) {
                    $gAcountData['image_path'] = $groupLogoPath;
                }

                $saveAccountDetails = $this->UpdateOrCreateRecord('savings_groups', $gAcountData);
                $OtherHelpers->addAmemberIntoAgroup([...$req, 'group_id' => $saveAccountDetails->id], false, isset($req['id']));

                $List = app(TenantSavingsAccountService::class);

                return $List->groupAccountList();
            });
        });
    }

    public function groupAccountDelete()
    {
        $req = request();

        return $this->TryCatch(function () use ($req) {
            return $this->transaction(function () use ($req) {
                $this->DeleteRecord('savings_groups', request());
                $this->DeleteRecord('savings_group_members', request(), condition: ['savings_group_id' => $req->id]);
                $List = app(TenantSavingsAccountService::class);

                return $List->groupAccountList();
            });
        });
    }
}
