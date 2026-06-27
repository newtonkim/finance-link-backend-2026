<?php

namespace App\Tenant\Services\MemebersSettingSevices;

use App\Http\Globals\GlobalHelpers;
use Illuminate\Support\Facades\DB;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;

class MemberHelpers extends GlobalHelpers
{ // / this is shared helpers/methods

    public function transactionUorCFields($data)
    {
        return $this->removeAllNullValues(
            [
                'umbrella_code' => $data['umbrella_code'],
                'reference' => $data['reference'],
                'receipt_number' => $data['receipt_number'] ?? $data['umbrella_code'] ?? $data['reference'] ?? null,
                'code' => $data['code'] ?? null,
                'member_id' => $data['member'],
                'savings_account_transfers_id' => $data['savings_account_transfers_id'] ?? null,
                'type' => $data['transaction_type'],
                'amount' => $data['amount'],
                'charge_amount' => $data['charge_amount'],
                'payment_mode' => $data['payment_method'],
                'payment_mod_account_id' => $data['payment_mode_id'] ?? null,
                'deposited_by' => $data['deposited_by'] ?? 'System (Initial Deposit)',
                'transaction_date' => $data['transaction_date'],
                'account_id' => $data['accid'],
                'account_type' => $data['type'] ?? '',
                'narration' => $data['narration'],
                'amount_before_transactions' => $data['b4trn'] ?? null,
                'deposited_amount_before_charge' => $data['deposited_amount_before_charge'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'meta_details_before_transaction' => $data['meta_details'] ?? null,
                'is_reversible' => $data['reversible'] ?? null,
                'is_reversed' => $data['is_reversed'] ?? null,
            ]
        );
    }

    public function createNewSaccoMemebers($fields, $req)
    {
        return $this->transaction(function () use ($req, $fields) {
            $caller = new ProductChargesservice;

            $isExisting = $fields['member_type'] === 'existing_member';
            $codeSequence = new CodeSequence;
            $settings = new FindsettingsAction(null);
            $dataField = $fields; // $this->mememberUOrCFields($req);
            $saveTwoAccounts = $settings->saccoMemberSaveAndSavingAccountAtOnce();
            // return $codeSequence->codeSequence(type: 'transactions', tableTaget: 'transactions');
            $code = $codeSequence->codeSequence($req['code'] ?? null, 'members', 'members-onboarding', 'members');
            $dataField['member_number'] = $code;
            $dataField['code'] = $code;
            $dataField['status'] = $settings->saccoMemberRequireApprovalBeforeMemberBecomesActive();
            $createTransactionAlso = $settings->saccoAccountOnAccountCreationShowInitialDeposit();
            // retur÷n $dataField;

            $dataField['password'] = $this->memberDefaultPassword($dataField['code']);
            $memberTableDetails = $this->UpdateOrCreateRecord('members', $dataField);
            $checkIfCreated =  $memberTableDetails?->id ?? $memberTableDetails['id'] ?? null;
            if (!$checkIfCreated) {

                return (array) $memberTableDetails;
                // throw new \Exception($, 500);
            }
            //  return ;
            $shareTableDetails = [];
            $chargedAmount = 0;
            $transactionDetails = [];
            $accountDetails = [];
            $deposit = (float) $memberTableDetails->initial_deposit;
            $umbrella_code = $this->umbrella_code();


            if ($saveTwoAccounts && isset($req['product_id'])) { // / create  a savings account for the member
                $saccoAcountData = [ // / values for the savings account for both
                    'member_id' => $memberTableDetails->id,
                    'savings_product_id' => $req['product_id'] ?? null,
                    // 'account_type' => 'voluntary',
                    'is_new_account' => true,
                    'consider_min_balance' => true,
                    'payment_mod_account_id' => $req['payment_mode_id'] ?? null,
                    'status' => 'active',
                    'branch_id' => $req['branch_id'] ?? null,
                    'account_opening_balance' => $req['opening_balance'] ?? 0,
                    'code' => $req['account_number'] ?? $codeSequence->codeSequence(type: 'savings-accounts', tableTaget: 'savings_accounts'),
                    'initial_deposit' => $deposit,
                ];

                $listCharges = [];

                if ($isExisting) {
                    // if ($isExisting && ! empty($req['product_id'])) {
                } else {

                    // $geTheGenericProductAcount =$req['product_id'];
                    // $geTheGenericProductAcount = (object) ["id" => $req['product_id']] ?? DB::table('savings_products')->where('name', 'General Savings Account')->first(['id']);
                    // $saccoAcountData = [
                    //     ...$saccoAcountData,
                    //     // 'savings_product_id' => $geTheGenericProductAcount,
                    // ];
                    if ($deposit > 0) {
                        $generalTotalCharges = 0;
                        $getGeneralCharges = $caller->GeneralProductCharges($saccoAcountData['savings_product_id'], 'on_registration');
                        if ($getGeneralCharges->isNotEmpty()) {
                            foreach ($getGeneralCharges as $generalCharge) {
                                $generalTotalCharges += is_numeric($generalCharge->amount) ? (float) $generalCharge->amount : 0;
                                $listCharges['general_charge_' . $generalCharge->id] = [
                                    "payment_mode_id" => $generalCharge->credit_account_id ?? null,
                                    'narration' => 'general charge deducted on registration ',
                                    'type' => 'general-charge',
                                    'transaction_type' => 'general-charge',
                                    'account_type' => 'general-charge',
                                    'charge_amount' => $generalCharge->amount
                                ];
                            }
                        }
                        request()->merge(['type' => 'deposit', 'amount' => $deposit, 'product_id' => $saccoAcountData['savings_product_id'] ?? null]);

                        $getTheProductCharges = $caller->productCharges();

                        $chargedAmount = $getTheProductCharges->cost + $generalTotalCharges;

                        $deposit = $deposit - $chargedAmount;
                        $listCharges['deposit'] = [
                            'payment_mode_id' => $req['payment_mode_id'],
                            'narration' => 'Initial deposit: ' . $chargedAmount . ' blc :' . $deposit,
                            'type' => 'deposit',
                            'amount' => $deposit,
                            'amount_before_charges' => $memberTableDetails->initial_deposit
                        ];
                        if ($chargedAmount > 0)
                            $listCharges['deposit_charge'] = [
                                'payment_mode_id' => $req['payment_mode_id'],

                                'narration' => 'charge for initial deposit',
                                'transaction_type' => 'deposit-charge',
                                'type' => 'deposit-charge',
                                'charge_amount' =>  $getTheProductCharges->cost
                            ];
                        if ($createTransactionAlso && $deposit < 0) {
                            DB::rollBack();
                            return $this->amountError($deposit);
                        }
                    }
                }
                $saccoAcountData['balance'] = $isExisting ? $req['opening_balance'] : $deposit;
                $accountDetails = $this->UpdateOrCreateRecord('savings_accounts', $saccoAcountData);
            }
            // ///// share account for the member
            $checkIfShareAccountShouldBeCreated = $settings->saccoMemberOnMemberCreationCreateShareAccountAtTheSameTime();
            $shareQty = (float) $memberTableDetails->shares_quantity;
            if ($checkIfShareAccountShouldBeCreated && (isset($req['product_id']) || isset($req['id']))) { // / lets  check 1st then we do the rest  t save memmorry
                $saccoMemberOnMemberCreationCreateShareMinimumValue = $settings->saccoMemberOnMemberCreationCreateShareMinimumValue();
                if ($saccoMemberOnMemberCreationCreateShareMinimumValue >= $shareQty) {
                    $sharePrice = $settings->saccoSharePrice();

                    $shareAccount = [
                        'code' => $codeSequence->codeSequence(type: 'shares', tableTaget: 'shares'),
                        'member_id' => $memberTableDetails->id,
                        'share_no' => $shareQty,
                        'share_value' => $sharePrice ?? 1,
                        'total_value' => ((int) $shareQty * $sharePrice),
                        'purchased_at' => now()->toDateString(),
                        'branch_id' => $req['branch_id'],
                    ];

                    if (!isset($req['id'])) {
                        $shareTableDetails = $this->UpdateOrCreateRecord('shares', $shareAccount);

                        if ($shareTableDetails) {

                            $shareCharge =    $caller->shareTransactionCharges([
                                'shares' => $shareQty,
                                'type' => 'deposit',
                            ]);
                            if ($shareCharge->cost > 0) {

                                $listCharges['share_charge'] = [
                                    'payment_mode_id' => $req['payment_mode_id'],

                                    'narration' => 'charge for share purchase',
                                    'account_type' => 'share-transaction-selling-charge',
                                    'type' => 'share-transaction',
                                    'charge_amount' => $shareCharge->cost
                                ];
                                $listCharges['share'] = [
                                    'payment_mode_id' => $req['payment_mode_id'],

                                    'narration' => 'share purchase on member creation',
                                    'account_type' => "share purchase",
                                    'type' => 'share-transaction',
                                    'amount' => ((int) $shareQty * $sharePrice)
                                ];
                            }
                        }
                    } else {
                        $shareTableDetails = $this->UpdateOrCreateRecord('shares', $shareAccount, [
                            'member_id' => $memberTableDetails->id,
                        ]);
                    }
                } else {
                    DB::rollBack();
                    return ['error' => 'share quantity must be greater than or equal to ' . $saccoMemberOnMemberCreationCreateShareMinimumValue];
                    // throw new \Exception('share quantity must be greater than or equal to ' . $saccoMemberOnMemberCreationCreateShareMinimumValue);
                }
                // /////
                if (isset($listCharges) && count($listCharges) > 0) { // check it first  befor the next level save the RAM
                    foreach ($listCharges as $chargeType => $information) {
                        $TransactionData = $this->transactionUorCFields([
                            'umbrella_code' => $umbrella_code ?? null,
                            'reference' => $codeSequence->codeSequence(type: 'transactions', tableTaget: 'transactions'),
                            'code' => $codeSequence->codeSequence(type: 'transactions', tableTaget: 'transactions'),
                            'member' => $memberTableDetails->id,
                            'amount' => $information['amount'] ?? 0,
                            'charge_amount' => $information['charge_amount'] ?? 0,
                            "deposited_amount_before_charge" => $information['amount_before_charges'] ?? 0,
                            // "deposited_amount_before_charge" => $memberTableDetails->initial_deposit,
                            'payment_method' => $req['payment_mode'] ?? $req['payment_mode_id'] ?? 'cash',
                            'payment_mode_id' => $information['payment_mode_id']??null,
                            'deposited_by' => $memberTableDetails->name,
                            'transaction_date' => now()->toDateString(),
                            'accid' => $accountDetails->id,
                            'transaction_type' => $information['type'],
                            'account_type' => $information['account_type'] ?? null,
                            'narration' => $information['narration'],
                            'branch_id' => $req['branch_id'],
                        ]);
                        $cheker = $this->UpdateOrCreateRecord('transactions', $TransactionData);
                        if ((isset($checker) && ! isset($checker['error']))) {
                            throw new \Exception($cheker, 500);
                        }
                    }
                    // }
                }
            }

            return [
                'member' => $memberTableDetails,
                'share' => $shareTableDetails,
                'transaction' => $transactionDetails,
                'account' => $accountDetails,
            ];
        });
    }
}
