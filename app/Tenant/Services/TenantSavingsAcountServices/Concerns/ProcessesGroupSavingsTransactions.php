<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait ProcessesGroupSavingsTransactions
{
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
                throw new \Exception('You cannot withdraw beyond the guaranteed amount. '.$sumOfTheMoney);
            }
        }

        $newBalance = $currentBalance->balance - ($amount + $chargedAmount);
        $amountAtaHand = $amount - $chargedAmount;
        $transactionList['withdraw'] = ['amount' => ($amount + $chargedAmount), 'deposited_amount_before_charge' => $amount, 'group_member_account_balance_before_transaction' => $getMemberGroup->balance,    'narration' => $req['narration'] ?? ("You have successfully withdrawn UGX {$amount}, including a charge of UGX {$chargedAmount}."),     'type' => 'withdraw',     'transaction_type' => 'withdraw'];
        $transactionList['deposit_charge'] = ['type' => 'withdraw',    'narration' => 'Withdrawal Charge',    'charge_amount' => $chargedAmount,    'transaction_type' => 'withdraw'];

        return ['transaction-list' => $transactionList, 'balance' => $newBalance];
    }

    private function groupDepositeMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup)
    {
        $newBalance = $currentBalance->balance + ($amount - $chargedAmount);
        $transactionList['deposit'] = ['amount' => ($amount - $chargedAmount), 'deposited_amount_before_charge' => $amount, 'group_member_account_balance_before_transaction' => $getMemberGroup->balance,   'narration' => $req['narration'] ?? ($req['type'] === 'deposit' ? "You have successfully deposited UGX {$amount}, after a charge of UGX {$chargedAmount}.  to you Group Account" : "You have successfully withdrawn UGX {$amount}, including a charge of UGX {$chargedAmount}."),    'type' => 'deposit',    'transaction_type' => 'deposit'];
        $transactionList['deposit_charge'] = ['type' => 'deposit-Charge',    'narration' => 'Deposit Charge',    'charge_amount' => $chargedAmount,    'transaction_type' => 'deposit-charge'];

        return ['transaction-list' => $transactionList, 'balance' => $newBalance];
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
                $codeSequence = new CodeSequence;

                $groupId = $req['group_account_id'];
                $table = 'group_savings_accounts';
                $currentBalance = DB::table($table)->where('id', $groupId)->first(['balance', 'savings_product_id', 'id', 'savings_group_id']);
                if (! $currentBalance) {
                    throw new \Exception('Group savings account not found.');
                }
                $amount = (float) $req['amount'];
                request()->merge(['type' => $req['type'] === 'deposit' ? 'deposit' : 'withdraw', 'amount' => $amount, 'product_id' => $currentBalance->savings_product_id]);
                $caller = new ProductChargesservice;

                $getTheProductCharges = $caller->productCharges();
                $chargedAmount = $getTheProductCharges->cost;
                if ($req['type'] === 'deposit') {
                    if ((float) $chargedAmount === (float) $req['amount']) {
                        throw new \Exception('You cannot deposit the same amount as the charge.');
                    }

                    $getMemberGroup = DB::table('savings_group_members')
                        ->where('savings_group_id', $currentBalance->savings_group_id)
                        ->where('member_id', $req['member_id'])
                        ->whereNull('deleted_at')
                        ->first(['balance', 'id']);
                    if (! $getMemberGroup) {
                        throw new \Exception('Selected member does not belong to this group.');
                    }
                    $collection = $this->groupDepositeMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup);

                    $newBalance = $collection['balance'];
                    $transactionList = $collection['transaction-list'];
                    // $transactionList['group_member_account_balance_before_transaction'] = $getMemberGroup->balance;
                    $newMemberBalance = (float) ($getMemberGroup->balance ?? 0) + ($amount - $chargedAmount);
                    $memberBalanceUpdated = DB::table('savings_group_members')
                        ->where('id', $getMemberGroup->id)
                        ->whereNull('deleted_at')
                        ->update([
                            'balance' => $newMemberBalance,
                            'updated_at' => now(),
                            'updated_by' => Auth::check() ? Auth::id() : null,
                        ]);
                    if ($memberBalanceUpdated !== 1) {
                        throw new \Exception('Failed to update group member deposited amount.');
                    }
                } else {

                    $getMemberGroup = DB::table('savings_group_members')
                        ->where('savings_group_id', $currentBalance->savings_group_id)
                        ->where('member_id', $req['member_id'])
                        ->whereNull('deleted_at')
                        ->first(['balance', 'id']);
                    if (! $getMemberGroup) {
                        throw new \Exception('Selected member does not belong to this group.');
                    }

                    // Approval gate: unless this is the execution of an already
                    // approved request, hold the withdrawal for group approvers.
                    if (empty($req['_approved_request_id'])) {
                        $required = $this->groupWithdrawalRequiredApprovals($currentBalance->savings_group_id);
                        if ($required > 0) {
                            return $this->createGroupWithdrawalRequestRow(
                                $currentBalance,
                                $req,
                                $chargedAmount,
                                $required,
                                $getMemberGroup
                            );
                        }
                    }

                    $collection = $this->groupWithdrawalMethod($currentBalance, $amount, $chargedAmount, $req, $getMemberGroup);
                    $newBalance = $collection['balance'];
                    $transactionList = $collection['transaction-list'];
                    // $transactionList['group_member_account_balance_before_transaction'] = $getMemberGroup->balance;
                    $newMemberBalance = (float) ($getMemberGroup->balance ?? 0) - ($amount + $chargedAmount);
                    if ($newMemberBalance < 0) {
                        throw new \Exception('Insufficient member group balance.');
                    }
                    $memberBalanceUpdated = DB::table('savings_group_members')
                        ->where('id', $getMemberGroup->id)
                        ->whereNull('deleted_at')
                        ->update([
                            'balance' => $newMemberBalance,
                            'updated_at' => now(),
                            'updated_by' => Auth::check() ? Auth::id() : null,
                        ]);
                    if ($memberBalanceUpdated !== 1) {
                        throw new \Exception('Failed to update group member deposited amount.');
                    }
                }
                if ($newBalance < 0) {
                    throw new \Exception('Insufficient balance.');
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

    /**
     * Number of designated approvers that must sign off a member withdrawal
     * from this group. 0 = no approval required (execute immediately).
     */
}
