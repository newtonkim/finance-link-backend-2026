<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\MemberHelpers;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAccountService;
use App\Tenant\Services\TenantSavingsAcountServices\OtherHelpers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ManagesMemberSavings
{
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
                $trasactionList['withdrawal'] = ['amount' => $amountNeeded, 'charge_amount' => $chargedAmount, 'payment_mode_id' => $req['payment_mode_id'] ?? null, 'transaction_type' => 'withdrawal', 'narration' => $feedBack ?? null, 'type' => 'withdrawal'];
                $trasactionList['charge_amount'] = ['charge_amount' => $chargedAmount, 'payment_mode_id' => null,  'narration' => 'withdrawal charges for this account of '.$amountNeeded, 'transaction_type' => 'withdrawal-charge', 'type' => 'withdrawal-charge'];
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
                    $memberIds = $this->normalizeMemberIds($req['memberslist'] ?? []);
                    $memberList = implode(',', $memberIds);

                    $canBeInMultipleGroups = $settings->canMemberExisitsInMultipleGroups();
                    if (in_array($canBeInMultipleGroups, ['false', '', null])) {
                        $checkIfMemberExistsInGroup = DB::table('savings_group_members')
                            ->leftJoin('members', 'members.id', '=', 'savings_group_members.member_id')
                            ->whereNull('savings_group_members.deleted_at')
                            ->where('savings_group_members.savings_group_id', '!=', $getGroupId)
                            ->whereIn('member_id', $memberIds)
                            ->get(['members.name', 'members.id']);
                        if (isset($checkIfMemberExistsInGroup) && count($checkIfMemberExistsInGroup) > 0) {
                            $membernameString[] = implode(',', $checkIfMemberExistsInGroup->pluck('name')->toArray());

                            $existingMemberIds = $checkIfMemberExistsInGroup->pluck('id')->map(fn ($id) => (string) $id)->toArray();
                            $fileterMembers = array_filter($memberIds, fn ($member) => ! in_array((string) $member, $existingMemberIds, true));
                            $memberList = implode(',', $fileterMembers);
                        }
                    }
                    if (empty($memberList) && count($membernameString)) {
                        throw new \Exception('These members already belong to another group: '.implode(',', $membernameString), 400);
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
                        throw new \Exception('share quantity must be greater than or equal to '.$checker['error'].' shares', 400);
                    }
                    $memberList = $checker['member']->id;
                }
                $OtherHelpers->addAmemberIntoAgroup(['memberslist' => $memberList, 'group_id' => $getGroupId, 'group_account_id' => $req['account_code'] ?? null, 'member_role' => $req['member_role'] ?? null], true);
                $List = app(TenantSavingsAccountService::class);
                $response = $List->groupAccountList();
                if (count($membernameString)) {
                    $response['warning'] = 'These members already belong to another group: '.implode(',', $membernameString);
                }

                return $response;
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
                    'narration' => $req['reference'].": $acountblc :".$req['narration'],
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
}
