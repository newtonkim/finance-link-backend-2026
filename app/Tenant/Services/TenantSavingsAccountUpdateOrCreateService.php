<?php

namespace App\Tenant\Services;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\MemberHelpers;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAcountServices\CrudHelders;
use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class TenantSavingsAccountUpdateOrCreateService extends CrudHelders
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
                "payment_mod_account_id" => $data['payment_mode_id'] ?? null
            ]
        );
    }

    public function importGroups()
    {
        $req = request()->all();
        $failed = [];
        $CrudHelders = new CrudHelders;
        $codeSequence = new CodeSequence;
        $chunks = 300; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $savingProductList = $CrudHelders->listproductIds($collection->rows, key: 'savings_product');
        $idChunks = array_chunk($collection->rows, $chunks);
        $branch_id = $req['branch_id'];
        foreach ($idChunks as $chunkIndex => &$chunkIds) { // pass chunk by reference
            foreach ($chunkIds as $value) { // pass by reference
                try {
                    if (strlen($value->group_name) == 0 || strlen($value->savings_product) == 0 || strlen($value->group_type) == 0) {
                        $value->reason = 'group_name or savings_product or group_type is empty';
                        $failed[] = $value;
                    } else {
                        $code = $codeSequence->codeSequence($req['group_code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_groups');
                        $date = $value->date_created ?? now();
                        $workedOnIdsFields = [
                            'name' => $value->group_name,
                            'group_type' => $value->group_type,
                            'primary_contact_phone' => $value->primary_contact_phone,
                            'date_created' => $date,
                            'created_at' => $date,
                            'location' => $value->group_location ?? NULL,
                            'branch_id' => $branch_id,
                            'code' => $code,
                            'description' => $value->group_description ??  'no destription during migration',
                        ];
                        $inThisInsetCheck = $this->UpdateOrCreateRecord('savings_groups', $workedOnIdsFields);
                        $details = $this->UpdateOrCreateRecord('group_savings_accounts', [
                            'savings_group_id' => $inThisInsetCheck->id,
                            'branch_id' => $branch_id,
                            'savings_product_id' => $savingProductList[$value->savings_product] ?? null,
                            "opening_balance" => $value->opening_balance ?? 0,
                            "initial_deposit" => $value->initial_deposit ?? 0,
                        ]);

                        $workedOnIds[] = $workedOnIdsFields;
                        if (isset($inThisInsetCheck->error)) {
                            $convert = ($this->isJSONToArray($inThisInsetCheck));
                            $convert2 = ($this->isJSONToArray($value));
                            $convert2->member_code = $convert['member_code'];
                            $convert2->reason = $convert['message'];
                            $failed[] = $convert2; // [$convert2,$convert];
                        }
                    }
                } catch (\Throwable $th) {
                    $value->reason = $th->getMessage();
                    $failed[] = $value;
                }
            }
            unset($value); // break reference
        }
        unset($chunkIds);
        if (! empty($failed)) {
            return ['failed' => $failed];
        }
        return $workedOnIds;
    }


    public function importGroupAccountMembers()
    {
        $req = request()->all();
        $failed = [];
        $CrudHelders = new CrudHelders;
        $codeSequence = new CodeSequence;
        $chunks = 300; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $memberCodedList = $CrudHelders->listMemberIds($collection->rows, key: 'member_code');
        $listsavingsGroupsds = $CrudHelders->listsavingsGroupsds($collection->rows, key: 'group_code');
        $idChunks = array_chunk($collection->rows, $chunks);
        foreach ($idChunks as $chunkIndex => &$chunkIds) { // pass chunk by reference
            foreach ($chunkIds as $value) { // pass by reference

                try {
                    //code...
                    if (isset($memberCodedList[$value->member_code])) {
                        $code = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_groups');
                        $workedOnIdsFields = [
                            'member_id' => $memberCodedList[$value->member_code] ?? null,
                            'savings_group_id' => $listsavingsGroupsds[$value->group_code],
                            'role' => $value->role ?? 'member',
                            'branch_id' => $req['branch_id'],
                            'code' => $value->code ?? $code

                        ];
                        $inThisInsetCheck = $this->UpdateOrCreateRecord('savings_group_members', $workedOnIdsFields);
                        $workedOnIds[] = $workedOnIdsFields;
                        if (isset($inThisInsetCheck->error)) {
                            $convert = ($this->isJSONToArray($inThisInsetCheck));
                            $convert2 = ($this->isJSONToArray($value));
                            $convert2->member_code = $convert['member_code'];
                            $convert2->reason = $convert['message'];
                            $failed[] = $convert2; // [$convert2,$convert];
                        }
                    } else {
                        $failed[] = $value;
                    }
                } catch (\Throwable $th) {
                    $value->reason = $th->getMessage();
                    $failed[] = $value;
                }
            }
            unset($value); // break reference
        }
        unset($chunkIds);
        if (! empty($failed)) {
            return ['failed' => $failed];
        }
        return $workedOnIds;
    }
    private function groupWithdrawalMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup)
    {
        $settings = new FindsettingsAction(['savings-group']);
        $catWidrawalOverGuaranteedAmount = $settings->inAGroupCantWithrawBeyondGuaranteedAmount();

        if ($catWidrawalOverGuaranteedAmount) {
            $sumOfTheMoney = DB::table('loan_application_guarantors')
                ->whereRaw('guarantor_id=?', [$currentBalance->savings_group_id])
                ->where('guarantor_type', 'group')
                ->sum('guarantee_amount');
            if ($sumOfTheMoney > 0 && $sumOfTheMoney <= $amount) {
                return throw new \Exception('You cannot withdraw beyond the guaranteed amount. ' . $sumOfTheMoney);
            }
        }

        $newBalance = $currentBalance->balance - ($amount + $chargedAmount);
        $amountAtaHand = $amount - $chargedAmount;
        $transactionList['withdraw'] = ["amount" => ($amount + $chargedAmount), 'deposited_amount_before_charge' => $amount, 'group_member_account_balance_before_transaction' => $getMemberGroup->balance,    "narration" =>  $req['narration'] ??  ("You have successfully withdrawn UGX {$amount}, including a charge of UGX {$chargedAmount}."),     "type" => 'withdraw',     "transaction_type" => 'withdraw',];
        $transactionList['deposit_charge'] = ["type" => "withdraw",    'narration' => "Withdrawal Charge",    "charge_amount" => $chargedAmount,    'transaction_type' => 'withdraw',];
        return ['transaction-list' => $transactionList, "balance" => $newBalance];
    }
    private function groupDepositeMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup)
    {
        $newBalance = $currentBalance->balance + ($amount - $chargedAmount);
        $transactionList['deposit'] = ["amount" => ($amount - $chargedAmount), 'deposited_amount_before_charge' => $amount, 'group_member_account_balance_before_transaction' => $getMemberGroup->balance,   "narration" =>  $req['narration'] ?? ($req['type'] === 'deposit' ? "You have successfully deposited UGX {$amount}, after a charge of UGX {$chargedAmount}.  to you Group Account" : "You have successfully withdrawn UGX {$amount}, including a charge of UGX {$chargedAmount}."),    "type" => 'deposit',    "transaction_type" => 'deposit',];
        $transactionList['deposit_charge'] = ["type" => "deposit-Charge",    'narration' => "Deposit Charge",    "charge_amount" => $chargedAmount,    'transaction_type' => 'deposit-charge',];
        return ['transaction-list' => $transactionList, "balance" => $newBalance];
    }
    public function groupSavingAccountDepositWithdrawal()
    {
        request()->validate([
            'group_account_id' => ['required', 'string'],
            'member_id' => ['required', 'string'],
            'deposited_by' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'type' => ['required', 'string', 'in:deposit,withdrawal'],
            'narration' => ['nullable', 'string', 'min:3', 'max:500'],
        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $codeSequence = new CodeSequence;;

                $groupId = $req['group_account_id'];
                $table = 'group_savings_accounts';
                $currentBalance = DB::table($table)->where('id', $groupId)->first(['balance', 'savings_product_id', 'id', 'savings_group_id']);
                if (! $currentBalance) {
                    return throw new \Exception('Group savings account not found.');
                }
                $amount = (float) $req['amount'];
                request()->merge(['type' => $req['type'] === 'deposit' ? 'deposit' : 'withdraw', 'amount' => $amount, 'product_id' => $currentBalance->savings_product_id]);
                $caller = new ProductChargesservice;

                $getTheProductCharges = $caller->productCharges();
                $chargedAmount = $getTheProductCharges->cost;
                if ($req['type'] === 'deposit') {
                    if ((float)$chargedAmount === (float)$req['amount']) {
                        throw new \Exception('You cannot deposit the same amount as the charge.');
                    }


                    $getMemberGroup = DB::table('savings_group_members')->where('member_id', $req['member_id'])->first(['balance', 'id']);
                    $collection = $this->groupDepositeMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup);

                    $newBalance = $collection['balance'];
                    $transactionList = $collection['transaction-list'];
                    if (! $getMemberGroup) {
                        return throw new \Exception('Group savings account not found.');
                    }
                    // $transactionList['group_member_account_balance_before_transaction'] = $getMemberGroup->balance;
                    $this->UpdateOrCreateRecord("savings_group_members", ['balance' => $getMemberGroup->balance + ($amount - $chargedAmount)], ['id' => $currentBalance->savings_group_id]);
                } else {

                    $getMemberGroup = DB::table('savings_group_members')->where('member_id', $req['member_id'])->first(['balance', 'id']);
                    $collection = $this->groupWithdrawalMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup);
                    $newBalance = $collection['balance'];
                    $transactionList = $collection['transaction-list'];
                    // $transactionList['group_member_account_balance_before_transaction'] = $getMemberGroup->balance;
                    if (! $getMemberGroup) {
                        return throw new \Exception('Group savings account not found.');
                    }
                    $this->UpdateOrCreateRecord("savings_group_members", ['balance' => $getMemberGroup->balance + ($amount - $chargedAmount)], ['id' => $currentBalance->savings_group_id]);
                }
                if ($newBalance < 0) {
                    return throw new \Exception('Insufficient balance.');
                }

                $details = $this->UpdateOrCreateRecord($table, ['balance' => $newBalance], ['id' => $groupId]);
                if (isset($details->error)) {
                    throw new \Exception($details->error);
                }

                $code = $this->umbrella_code();
                if (isset($transactionList) && count($transactionList) > 0) {
                    foreach ($transactionList as $key => $value) {
                        $TransactionData = $this->transactionUorCFields([
                            'reference' => $codeSequence->codeSequence($value['type'] == '' ? $req['transaction_reference'] : null, type: 'transactions', moduleTarget: 'transactions', tableTaget: 'transactions'),
                            'member' => $req['member_id'] ?? null,
                            'amount' => $value['amount'] ?? 0,
                            'group_member_account_balance_before_transaction' => $value['group_member_account_balance_before_transaction'] ?? 0,
                            'deposited_amount_before_charge' => $value['deposited_amount_before_charge'] ?? 0,
                            'umbrella_code' => $code ?? null,
                            'deposited_by' => $req['deposited_by'] ?? 'System (Initial Deposit)',
                            'payment_mode_id' => $req['payment_mode_id'] ?? null,
                            'charge_amount' => $value['charge_amount'] ?? 0,
                            'transaction_date' => $req['transact_date'] ?? Carbon::now()->toDateTimeString(),
                            'gsaid' => $currentBalance->id,
                            'narration' => $value['narration'] ?? null,
                            'b4trn' => $currentBalance->balance,
                            'transaction_type' => $value['transaction_type'],
                            'type' => $value['type'],
                        ]);
                        $this->UpdateOrCreateRecord('transactions', $TransactionData);
                    }
                }
            });
        });
    }

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
                if (! isset($req['id'])) {
                    $codeSequence = new CodeSequence;
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

    public function importOpeningBalance()
    {
        $req = request()->all();
        $failed = [];
        $chunks = 300; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $idChunks = array_chunk($collection->rows, $chunks);
        foreach ($idChunks as $chunkIndex => &$chunkIds) { // pass chunk by reference
            $ids = array_column($chunkIds, 'account_number');
            $workedOnIds = [];
            $getTheCollected = DB::table('savings_accounts as Acc')
                ->whereIn('Acc.code', $ids)
                ->where(function ($q) {
                    $q->whereNull('Acc.account_opening_balance')
                        ->orWhere('Acc.account_opening_balance', 0);
                })
                ->select(['Acc.id', 'balance'])
                ->orderBy('id')
                ->get()
                ->toArray();
            foreach ($chunkIds as &$value) { // pass by reference
                foreach ($getTheCollected as $collected) {
                    // return $value;
                    // $workedOnIds[] = $value['id']; //

                    $inThisInsetCheck = $this->UpdateOrCreateRecord('savings_accounts', [
                        'account_opening_balance' => $value->opening_balance,
                        'balance' => $collected->balance + $value->opening_balance,
                    ], ['code' => $value->account_number]);

                    if (isset($inThisInsetCheck->error)) {
                        $convert = ($this->isJSONToArray($inThisInsetCheck));
                        $convert2 = ($this->isJSONToArray($value));
                        $convert2->code = $convert['code'];
                        $convert2->reason = $convert['message'];
                        $failed[] = $convert2; // [$convert2,$convert];
                    }
                    // if ((isset($checker) && isset($checker['error'])) || (!$checker)) {
                    //     $errors[] = $value;
                    // }
                    break; // no need to check further
                }
            }
            unset($value); // break reference
        }
        unset($chunkIds);
        if (! empty($failed)) {
            return ['failed' => $failed];
        }

        return $workedOnIds;
    }


    public function memberAccountWithdrawal()
    {
        return $this->transaction(function () {
            $req = request()->all();
            $amountNeeded = (float) $req['amount'];
            $id = (float) $req['account_id'];
            $charge = new ProductChargesservice;
            $accountDetails = DB::table('savings_accounts')->where('id', $id)->first(['balance', 'member_id', 'savings_product_id', 'id']);
            // return $amountNeeded;
            request()->merge(['type' => 'withdraw', 'amount' => $amountNeeded, 'product_id' => $accountDetails->savings_product_id ?? null]);
            $chargeDetails = $charge->productCharges();
            $chargedAmount = $chargeDetails->cost;
            $amount = $amountNeeded; // Convention A: charge is deducted from the gross withdrawal, not added to the account debit.
            $netPaid = $amountNeeded - $chargedAmount;
            $getBlc = $accountDetails->balance; // balance before transaction, used to check the gross withdrawal debit.
            $computedBlc = $getBlc - $amount;
            $feedBack = null;
            $trasactionList = [];
            // return $amountNeeded;
            if ($computedBlc < 0) {
                $possibleAmountToWithdraw = $getBlc;
                $feedBack = "FAILED:Insufficient balance. 
        \n The account debit for this withdrawal is
        \n UGX {$amount}, but the current balance is UGX {$getBlc}.
        \n You can withdraw up to UGX {$possibleAmountToWithdraw}.";
            }

            if ($accountDetails->balance < $amount) {
                $feedBack = "FAILED:Insufficient balance. 
          \n The account debit for this withdrawal is
          \n UGX {$amount}, but the current balance is UGX {$accountDetails->balance},";
            }

            if ($chargedAmount >= $amountNeeded) {
                $feedBack = 'FAILED:Withdrawal charge must be less than the withdrawal amount.';
            }
            
            if ($computedBlc >= 0 && ! str_contains((string) $feedBack, 'FAILED')) {
                $this->UpdateOrCreateRecord(
                    'savings_accounts',
                    [
                        'balance' => $computedBlc,
                    ],
                    ['id' => $id]
                );
                $feedBack = $req['narration'] ?? "You have successfully withdrawn UGX {$amountNeeded}; net paid UGX {$netPaid}, charge UGX {$chargedAmount}.";
                $trasactionList['withdrawal'] = ['amount' => $amountNeeded, 'charge_amount' => $chargedAmount,'payment_mode_id'=>$req['payment_mode_id']??null, 'transaction_type' => "withdrawal", 'narration' => $feedBack ?? null, 'type' => 'withdrawal'];
                $trasactionList['charge_amount'] = ['charge_amount' => $chargedAmount,'payment_mode_id'=>null,  'narration' => 'withdrawal charges for this account of ' . $amountNeeded, 'transaction_type' => 'withdrawal-charge', 'type' => 'withdrawal-charge'];
                }

            $transactionDate = $req['transact_date'] ?? Carbon::now()->toDateTimeString();
            $codeSequence = new CodeSequence;

            $code = $codeSequence->codeSequence(null, type: 'transactions', moduleTarget: 'transactions', tableTaget: 'transactions');

            foreach ($trasactionList as $key => $value) {
                $codeUnique = $codeSequence->codeSequence(
                    $key === 'withdrawal' ? ($req['transaction_reference'] ?? null) : null,
                    type: 'transactions',
                    moduleTarget: 'transactions',
                    tableTaget: 'transactions'
                );
                $TransactionData = $this->transactionUorCFields([
                    'reference' => $codeUnique,
                    'code' => $codeUnique,
                    'umbrella_code' => $code ?? null,
                    'member' => $accountDetails->member_id,
                    'amount' => $value['amount'] ?? 0,
                    'transaction_type' => $value['transaction_type'] ?? null,
                    'payment_mode_id' => $value['payment_mode_id'],
                    // 'payment_method' => $value['payment_method'] ?? 'cash',
                    'deposited_by' => $req['withdrawal_by'] ?? null,
                    'charge_amount' => $value['charge_amount'] ?? null,
                    'transaction_date' => $transactionDate,
                    'accid' => $accountDetails->id,
                    'type' => $value['type'],
                    'narration' => $value['narration'] ?? null,
                    'b4trn' => $accountDetails->balance, 
                ]);
                // payment_mode

                $this->UpdateOrCreateRecord('transactions', $TransactionData);
            }

            if (str_contains($feedBack, 'FAILED')) {
                return [
                    'code' => 422,
                    'message' => $feedBack,
                    'error' => $feedBack,
                ];
            }

            $List = app(TenantSavingsAccountService::class);

            return $List->memberAccountList();
        });
    }

    public function makeAccountsComputation($from, $to, $amount)
    {
        $amount = (float) $amount;
        $getTransferFrom = DB::table('savings_accounts')
            ->where('id', $from)
            ->where('balance', '>=', $amount)
            ->first(['balance', 'id']);

        if ($getTransferFrom) {
            DB::table('savings_accounts')->where('id', $from)->decrement('balance', $amount);
            DB::table('savings_accounts')->where('id', $to)->increment('balance', $amount);

            return true;
        }

        return false;
        // throw new \Exception('Insufficient balance');
    }

    public function groupNoneMembersCreate()
    {
        request()->validate([
            'branch_id' => ['required', 'numeric'],
            'add_existing_members_ogroup' => ['nullable'],
            'group_id' => ['required', 'string'],
            'group_description' => ['nullable', 'string', 'max:100'],

        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $OtherHelpers = new OtherHelpers;
                $getGroupId = $req['group_id'];
                $membernameString = [];
                $settings = new FindsettingsAction(['savings-group']);

                if (isset($req['add_existing_members_ogroup']) && $req['add_existing_members_ogroup'] == true) {

                    $canBeInMultipleGroups = $settings->canMemberExisitsInMultipleGroups();
                    if (in_array($canBeInMultipleGroups, ['false', '', null])) {
                        $memberList = $req['memberslist'];

                        $checkIfMemberExistsInGroup = DB::table('savings_group_members')
                            ->leftJoin('members', 'members.id', '=', 'savings_group_members.member_id')
                            ->whereIn('member_id', explode(',', $req['memberslist']))->get('members.name', 'id');
                        if (isset($checkIfMemberExistsInGroup) && count($checkIfMemberExistsInGroup) > 0) {
                            $membernameString[] = implode(',', $checkIfMemberExistsInGroup->pluck('name')->toArray());

                            $fileterMembers = array_filter(explode(',', $memberList), function ($member) use ($checkIfMemberExistsInGroup) {
                                if (in_array($member, array_column($checkIfMemberExistsInGroup->toArray(), 'id'))) {
                                    return $member;
                                }
                            });
                            $memberList = implode(',', $fileterMembers);
                        }
                    }
                } else {
                    // / create first member the flow
                    $fields = $this->groupNoneMembersCreateUorCFields($req);
                    $fields = [
                        ...$fields,
                        'member_type' => 'new_member',
                        'created_from' => 'group-savings',
                        'is_external_member' => true,

                    ];
                    $memberService = new MemberHelpers;
                    $checker = $memberService->createNewSaccoMemebers($fields, $req);
                    if (isset($checker) && isset($checker['error'])) {
                        throw new \Exception('share quantity must be greater than or equal to ' . $checker['error'] . ' shares', 400);
                    }
                    $memberList = $checker['member']->id;
                }
                $OtherHelpers->addAmemberIntoAgroup(['memberslist' => $memberList, 'group_id' => $getGroupId, 'group_account_id' => $req['account_code'] ?? null], true);
                $List = app(TenantSavingsAccountService::class);
                if (count($membernameString)) {
                    throw new \Exception('Group can only have one member Likes: ' . implode(',', $membernameString) . ' are skipped ', 400);
                }

                return $List->groupAccountList();
            });
        });
    }

    public function transferAmountApprove()
    {

        $table = 'savings_account_transfers';
        $saveTransferDetails = DB::table($table)
            ->where(function ($query) {
                $query->whereRaw('code=?', [request()->code])
                    ->orWhereRaw('id=?', [request()->id])
                    ->orWhereRaw('code=?', [request()->reference]);
            })
            ->where('status', 'pending')
            ->first();

        if (isset($saveTransferDetails)) {
            $transactionDate = date('Y-m-d H:i:s');
            $this->completeTheTransfer($saveTransferDetails, [], $transactionDate, $table);
        }

        $List = app(TenantSavingsAccountService::class);

        return $List->transferList();
    }

    protected function completeTheTransfer($saveTransferDetails, $fields, $transactionDate, $table)
    {
        $checkBlc = $this->makeAccountsComputation($saveTransferDetails->from_account_id, $saveTransferDetails->to_account_id, $saveTransferDetails->amount);
        $newField['status'] = $checkBlc ? 'completed' : 'failed';
        if ($saveTransferDetails->id) {
            $newField['transaction_date'] = $transactionDate;
            // $newField['narration'] = $req['norration'];
            $this->UpdateOrCreateRecord($table, $newField, updateCondition: ['id' => $saveTransferDetails->id]);
        }

        $getmemberId = DB::table('savings_accounts')->where('id', $saveTransferDetails->to_account_id)->first(['member_id']);
        $this->UpdateOrCreateRecord('transactions', [
            'reference' => $saveTransferDetails->code,
            'amount' => $saveTransferDetails->amount,
            'type' => 'Transfer',
            'member_id' => $getmemberId->member_id, // id of the member who received the transfer
            'branch_id' => $fields['branch_id'] ?? null,
            'payment_mode' => 'Transfer',
            'transaction_date' => $transactionDate,  // let the date to complete
            'narration' => $saveTransferDetails->description,
            'savings_account_transfers_id' => $saveTransferDetails->id,
        ]);
    }

    public function transferAmountCreate()
    {
        // / peer to peer transfer
        request()->validate([
            'code' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'description' => ['nullable', 'string', 'min:3', 'max:500'],
            'from' => ['required', 'numeric', 'exists:savings_accounts,id'],
            'to' => ['required', 'numeric', 'exists:savings_accounts,id'],
        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $table = 'savings_account_transfers';
                $checkForApproval = new FindsettingsAction(['transfer-savings']);
                $approvalNeeded = $checkForApproval->saccoTransferSavingsRequireApprovalToBecomeAcompleteTransfer();
                $req = request()->all();
                $fields = $this->transferUorCFields($req);

                $codeSequence = new CodeSequence;
                $fields['code'] = $codeSequence->codeSequence($fields['code'] ?? null, type: 'transfer-savings', moduleTarget: 'transfer-savings', tableTaget: $table);
                $fields['status'] = $approvalNeeded ? 'completed' : 'Pending';

                $saveTransferDetails = $this->UpdateOrCreateRecord($table, $fields);
                $transactionDate = $req['transact_date'] ?? Carbon::now()->toDateTimeString();
                if ($approvalNeeded && $saveTransferDetails) {
                    $this->completeTheTransfer($saveTransferDetails, $fields, $transactionDate, $table);
                    // $checkBlc = $this->makeAccountsComputation($saveTransferDetails->from_account_id, $saveTransferDetails->to_account_id, $saveTransferDetails->amount);
                    // $newField['status'] = $checkBlc ? 'completed' : 'failed';
                    // if ($saveTransferDetails->id) {
                    //     $newField['transaction_date'] = $transactionDate;
                    //     // $newField['narration'] = $req['norration'];
                    //     $this->UpdateOrCreateRecord($table, $newField, updateCondition: ['id' => $saveTransferDetails->id]);
                    // }

                    // $getmemberId = DB::table('savings_accounts')->where('id', $saveTransferDetails->to_account_id)->first(['member_id']);
                    // $this->UpdateOrCreateRecord('transactions', [
                    //     'reference' => $saveTransferDetails->code,
                    //     'amount' => $saveTransferDetails->amount,
                    //     'type' => 'Transfer',
                    //     'member_id' => $getmemberId->member_id, // id of the member who received the transfer
                    //     'branch_id' => $fields['branch_id'] ?? 1,
                    //     'payment_mode' => 'Transfer',
                    //     'transaction_date' => $transactionDate,  // let the date to complete
                    //     'narration' => $saveTransferDetails->description,
                    //     'savings_account_transfers_id' => $saveTransferDetails->id,
                    // ]);
                }
                $List = app(TenantSavingsAccountService::class);

                return $List->transferList();
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
                $gAcountData['image_path'] = $this->saveFile('group_logo', 'savings-group');

                $saveAccountDetails = $this->UpdateOrCreateRecord('savings_groups', $gAcountData);
                $OtherHelpers->addAmemberIntoAgroup([...$req, 'group_id' => $saveAccountDetails->id]);

                // $details = $this->UpdateOrCreateRecord('group_savings_accounts', $this->groupSavingsAccountUorCFields([ // just if ok just create group savings account too
                //     "savings_group_id" => $saveAccountDetails->id,
                //     "savings_product_id" => DB::table('savings_products')->whereRaw('name', 'General Savings Account')->first(['id'])->id,
                //     "branch_id" => $req['branch_id'] ?? null,
                //     "opening_balance"=>"0.00",
                //     "initial_deposit"=>"0.00",
                //     "balance"=>"0.00",
                //     "is_new_account"=>"0.00",
                //     "status"=>"0.00",

                // ]));

                $List = app(TenantSavingsAccountService::class);

                return $List->groupAccountList();
            });
        });
    }

    public function memberAccountCreate()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                if (in_array($req['new_account'], ['1', '0'])) {
                    return $this->isNewAccount();
                } elseif ($req['new_account'] == 'depositing') {
                    return $this->isExistingAccount();
                }
            });
        });
    }

    public function memberAccountDelete()
    {
        return $this->TryCatch(function () {
            $this->DeleteRecord('savings_accounts', request());
            $List = app(TenantSavingsAccountService::class);

            return $List->memberAccountList();
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

    public function importMembersWithdrawalAnddeposits()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $this->saveMigrationsFiles();
                $collection = $this->isJSONToArray($req['collection']);
                $AccountIds = $this->listMemeberAccountIds($collection->rows);
                $memberCodedList = $this->listMemberIds($collection->rows);
                $branchList = $this->listBranchIds($collection->rows);
                $chuckRow = array_chunk($collection->rows, 300);
                $failed = [];

                foreach ($chuckRow as $key1 => $value1) {
                    foreach ($value1 as $key => $value) {
                        $getMemberId = $memberCodedList[$value->code];
                        // branchList
                        if (isset($getMemberId)) {
                            $detaminTheType = isset($value->type) ? str_contains(strtolower($value->type), 'w') ? 'withdrawal' : 'deposit' : '';
                            if (isset($value->charge_by_system) && in_array($value->charge_by_system, ['true', true, 1, '1'])) {
                                request()->merge([
                                    'member' => $getMemberId,
                                    'amount' => $value->amount,
                                    'deposit' => $value->amount,
                                    'account_id' => $AccountIds[$value->account_code],
                                    'narration' => $value->narration,
                                    'transact_date' => $value->date ?? Carbon::now()->toDateTimeString(),
                                    'payment_method' => $value->method,
                                ]);
                                if ($value->type == 'withdrawal') {
                                    $inThisInsetCheck = $this->memberAccountWithdrawal();
                                } else {
                                    $inThisInsetCheck = $this->isExistingAccount();
                                }
                            } else {
                                $codeSequence = isset($value->type) ? str_contains(strtolower($value->type), 'w') ? 'WDL' : 'DPL' : '';
                                $inThisInsetCheck = $this->UpdateOrCreateRecord('transactions', [
                                    'reference' => 'SAC-' . $codeSequence . '-' . time() . '-' . mt_rand(10000000, 99999999),
                                    'member_id' => $getMemberId,
                                    'amount' => $value->amount,
                                    'payment_mode' => isset($value->method) ? $value->method : 'cash',
                                    'deposited_by' => 'System (' . $detaminTheType . ' data Migration)',
                                    'type' => $detaminTheType . '_charges',
                                    'charge_amount' => $value->charge,
                                    'transaction_date' => $value->date ?? Carbon::now()->toDateTimeString(),
                                    'account_id' => $AccountIds[$value->account_code],
                                    'account_type' => SavingsAccount::class, // account_type
                                    'narration' => $value->narration,
                                    'branch_id' => isset($value->branch_code) ? $branchList[$value->branch_code] : null,
                                ]);
                            }
                            if (isset($inThisInsetCheck->error)) {
                                $convert = ($this->isJSONToArray($inThisInsetCheck));
                                $convert2 = ($this->isJSONToArray($value));
                                $convert2->code = $convert['code'];
                                $convert2->reason = $convert['message'];
                                $failed[] = $convert2; // [$convert2,$convert];
                            }
                        } else {
                            $value->reason = 'Could not find member with be member code / name not matching';
                            $failed[] = $value;
                        }
                    }
                }
                if (count($failed) > 0) {
                    return $failed;
                }
            });
        });
    }

    public function memberAccountReversal()
    {
        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $trans = DB::table('transactions')
                    ->where('reference', $req['reference'])
                    ->where('type', '!=', 'reversed')
                    ->where('type', '!=', 'charge-reversal')
                    ->select('account_id', 'account_type', 'member_id', 'charge_amount', 'amount')->first();

                $account = DB::table('savings_accounts')->where(['id' => $trans->account_id])->first(['balance', 'id']);
                if (empty($account) && empty($trans)) {
                    throw new ('Account not found/Transaction not found');
                }
                // / i dont trust the increment  in bult  it does weire thing at times
                $totalAmount = $trans->amount + ($trans->charge_amount);
                $reversalType = (isset($req['charge_reversal']) && $req['charge_reversal']) ? 'charge-reversal' : 'reversed';

                // return $trans;
                if ($trans->account_type == 'deposit') {
                    if ($reversalType == 'charge-reversal') {
                        $acountblc = $account->balance - $trans->charge_amount;
                    } else {
                        $acountblc = $account->balance - $totalAmount;
                    }
                } else {
                    if (isset($req['charge_reversal']) && $req['charge_reversal']) { // let revet only charges
                        $acountblc = $account->balance + $trans->charge_amount;
                    } else {
                        $acountblc = $account->balance + $totalAmount;
                    }
                }
                //  $acountblc;

                $this->UpdateOrCreateRecord('savings_accounts', [
                    'id' => $account->id,
                    'balance' => $acountblc,
                ], [
                    'id' => $account->id,
                ]);
                $tranasctionCondition = [
                    'reference' => $req['reference'],
                ];
                if ($reversalType == 'charge-reversal') {
                    $this->UpdateOrCreateRecord(
                        'transactions',
                        [
                            'type' => $reversalType,
                            'charge_amount' => 0,
                        ],
                        $tranasctionCondition
                    );
                } else {
                    $this->UpdateOrCreateRecord('transactions', [
                        'type' => $reversalType,
                    ], $tranasctionCondition);
                }

                $codeSequence = new CodeSequence;
                $code = $codeSequence->codeSequence(mt_rand(10000000, 99999999), type: 'transfer-savings', moduleTarget: 'transfer-savings', tableTaget: 'transactions');
                $inThisInsetCheck = $this->UpdateOrCreateRecord('transactions', [
                    'reference' => $code, // . 'SAC-' . $code . '-' . time() . '-' . mt_rand(10000000, 99999999),
                    'member_id' => $trans->member_id,
                    'amount' => (isset($req['charge_reversal']) && $req['charge_reversal']) ? $trans->charge_amount : $totalAmount,
                    'payment_mode' => 'cash',
                    'deposited_by' => 'System (Account Reversal)',
                    // 'type' => $reversalType,
                    'is_reversed' => 1,
                    'charge_amount' => 0,
                    'transaction_date' => $req['date'] ?? Carbon::now()->toDateTimeString(),
                    'account_id' => $trans->account_id,
                    'account_type' => SavingsAccount::class, // account_type
                    'narration' => $req['reference'] . ": $acountblc :" . $req['narration'],
                    'branch_id' => $req['branch_id'] ?? null,
                ]);

                if (isset($inThisInsetCheck->error)) {
                    throw new ($inThisInsetCheck->error);
                }
                $List = app(TenantSavingsAccountService::class);

                return $List->memberAccountList();
            });
        });
    }

    public function importMemberAccounts()
    {

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();
                $collection = $this->isJSONToArray($req['collection']);
                $memberCodedList = $this->listMemberIds($collection->rows);
                $productCodedList = $this->listproductIds($collection->rows);
                $branches = $this->listBranchIds();
                $this->saveMigrationsFiles();

                $chuckRow = array_chunk($collection->rows, 300);
                $failed = [];

                foreach ($chuckRow as $key1 => $value1) {
                    foreach ($value1 as $key => $value) {
                        $getMemberId = $memberCodedList[$value->code];
                        $getProductId = $productCodedList[$value->product_code ?? 'default'];
                        // return  ['failed' => [$getMemberId,$getProductId]];

                        // / if member code is missing please  skip
                        if (isset($getMemberId) && isset($getProductId)) {
                            request()->merge([
                                'member' => $getMemberId,
                                // 'code' => $value->account_code,
                                'product_id' => $getProductId,
                                'in_deposit' => $value->in_deposit,
                                'opening_balance' => $value->opening_balance,
                                'new_account' => in_array($value->new_account, [1, '1', 'true', 'True', true]) ? 1 : '0', // /new_account
                                'cm_balance' => in_array($value->consider_min_balance, [1, '1', 'true', 'True', true]) ? 1 : '0', // /consider_min_balance
                                'status' => $value->status ?? 'active',
                                'branch_id' => isset($value->branch_code) ? $branches[$value->branch_code] : $req['branch_id'],
                            ]);
                            $inThisInsetCheck = $this->isNewAccount();

                            if ($inThisInsetCheck['error']) {
                                $convert = ($this->isJSONToArray($inThisInsetCheck));
                                $convert2 = ($this->isJSONToArray($value));
                                $convert2->code = $convert['code'];
                                $convert2->reason = $convert['message'];
                                $failed[] = $convert2; // [$convert2,$convert];

                            }
                        } else {
                            $value->reason = 'can be member code and product code are not matching';
                            $failed[] = $value;
                        }
                    }
                }
                if (empty($failed)) {
                    $List = app(TenantSavingsAccountService::class);

                    return $List->memberAccountList();
                }

                return ['failed' => $failed];
            });
        });
    }
}
