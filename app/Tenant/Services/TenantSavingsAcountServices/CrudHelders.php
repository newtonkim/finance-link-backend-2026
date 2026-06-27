<?php

namespace App\Tenant\Services\TenantSavingsAcountServices;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CrudHelders extends GlobalHelpers
{
    public $groupMemberRoles = [
        '1' => 'Admin',
        '2' => 'chairman',
        '3' => 'secretary',
        '4' => 'member',
    ];

    public function transactionUorCFields($data)
    {
        return $this->removeAllNullValues(
            [
                'reference' => $data['reference'] ?? null,
                'receipt_number' => $data['receipt_number'] ?? $data['umbrella_code'] ?? $data['reference'] ?? null,
                'code' => $data['code'] ?? null,
                'member_id' => $data['member'] ?? null,
                'savings_account_transfers_id' => $data['savings_account_transfers_id'] ?? null,
                'type' => $data['transaction_type'],
                // 'amount' => $data['payment_mode'],
                'amount' => $data['amount'],
                'deposited_amount_before_charge' => $data['deposited_amount_before_charge'] ?? $data['amount']??null,
                'group_member_account_balance_before_transaction' => $data['group_member_account_balance_before_transaction']??null,
                'charge_amount' => $data['charge_amount'],
                'umbrella_code' => $data['umbrella_code'] ?? null,
                'payment_mode' => $data['payment_method']?? null, 
                'deposited_by' => $data['deposited_by'] ?? 'System (Initial Deposit)',
                'transaction_date' => $data['transaction_date'],
                'account_id' => $data['accid'] ?? null,
                'group_savings_account_id' => $data['gsaid'] ?? null,
                'account_type' => $data['type'] ?? '',
                'narration' => $data['narration'],
                'payment_mod_account_id' => $data['payment_mode_id'] ?? null,
                'amount_before_transactions' => $data['b4trn'] ?? null,
                'meta_details_before_transaction' => $data['meta_details_before_transaction'] ?? null,
                'branch_id' => $req['branch_id'] ?? null,
            ]
        );
    }

    public function savingAccountUOrCFields($req)
    {
        return $this->removeAllNullValues([
            'payment_mod_account_id' => $req['payment_mode_id'] ?? null,
            'member_id' => $req['member'] ?? null,
            'savings_product_id' => $req['product_id'] ?? null,
            'is_new_account' => $req['new_account'] ?? null,
            'balance' => $req['blc'] ?? null,
            'initial_deposit' => $req['in_deposit'] ?? null,
            'account_opening_balance' => $req['opening_balance'] ?? null,
            'consider_min_balance' => $req['cm_balance']??null,
            // 'consider_min_balance' => $req['cm_balance'] >= 1 ? true : ($req['cm_balance'] == 0 ? '0' : null),
            // 'selected_charges' => $req['charges'] ?? null,
            'status' => $req['status'] ?? null,
            'branch_id' => $req['branch_id'] ?? null,
        ]);
    }

    public function isExistingAccount()
    {
        // / say tha existin no need of charges
        $req = request()->all();
        // return  trim($req['transaction_date'],'"');
        request()->validate([
            'deposit' => ['nullable', 'numeric', 'min:0'],
            'new_account' => ['required', 'in:1,depositing,0'],
            'account_id' => ['required', 'exists:savings_accounts,id'],
        ]);
        //code
        $chargedAmount = null;
        $to = $req['account_id'];
        $trasactionList = [];
        // $to = $req['group_id'];
        $deposit = (float) $req['deposit'];
        $checkfrTheproduct = DB::table('savings_accounts')->where('id', $to)->first(['savings_product_id AS product_id', 'member_id AS member', 'balance AS blc']);
        $settings = new FindsettingsAction(null);
        $createTransactionAlso = null;

        if ($deposit > 0) {
            $createTransactionAlso = $settings->saccoAccountOnAccountCreationShowInitialDeposit();
            request()->merge(['type' => 'deposit', 'amount' => $deposit, 'product_id' => $checkfrTheproduct?->product_id ?? null]);
            $caller = new ProductChargesservice;
            $getTheProductCharges = $caller->productCharges();
            $chargedAmount = $getTheProductCharges->cost;
            $deposit = $deposit - $chargedAmount;
            if ($createTransactionAlso && $deposit < 0) {
                return $this->amountError($deposit);
            }
            $trasactionList['deposit'] = ['deposited_amount_before_charge' => $req['deposit'], 'code' => $req['transaction_reference'] ?? null, 'amount' => $deposit, 'transaction_type' => "deposit", 'narration' => $req['narration'] ?? null, 'type' => 'deposit'];
            // $trasactionList['deposit'] = ['amount' => $deposit, 'transaction_type' => $getTheProductCharges?->charge_type, 'charge_amount' => $chargedAmount, 'narration' => $req['narration'], 'type' => 'deposit'];
            if ($chargedAmount > 0)
                $trasactionList['charge_amount'] = ['charge_amount' => $chargedAmount, 'narration' =>  'Deposit charges of '.$chargedAmount.'  for this amount of ' . $req['deposit'], 'transaction_type' => 'deposit-charge', 'type' => 'deposit-charge'];
        }

        $getAmount = DB::table('savings_accounts')->where('id', $to)->first(['balance AS blc', 'account_opening_balance', 'initial_deposit']);
        if (!$getAmount) {
            throw new \Exception("Savings account with ID {$to} not found.");
        }
        $codeSequence = new CodeSequence;

        DB::table('savings_accounts')->where('id', $to)->update(['balance' => $getAmount->blc + $deposit]);

        $code = $this->umbrella_code();
        foreach ($trasactionList as $key => $value) {
            $codeUnique = $codeSequence->codeSequence($value['code'] ?? null);

            $TransactionData = $this->transactionUorCFields([
                'member' => $checkfrTheproduct?->member ?? null,
                'deposited_amount_before_charge' => $value['deposited_amount_before_charge'] ?? null,
                'umbrella_code' => $code ?? null,
                'transaction_type' => $value['transaction_type'] ?? null,
                'amount' => $value['amount'] ?? 0,
                'charge_amount' => $value['charge_amount'] ?? null,
                'payment_mode_id' => $req['payment_mode_id'] ?? null,
                'deposited_by' => $req['deposited_by'] ?? null,
                'transaction_date' => isset($req['transaction_date']) ? Carbon::parse(trim($req['transaction_date'], '"'))->format('Y-m-d H:i:s') : now()->toDateString(),
                'accid' => $to,
                'type' => $value['type'],
                'narration' => $value['narration'] ?? null,
                'b4trn' => $checkfrTheproduct?->blc ?? null,
                'meta_details_before_transaction' => (array) $getAmount,
            ]);
            if (! isset($req['id'])) {

                $TransactionData['reference'] = $codeUnique;
                $TransactionData['code'] = $codeUnique;
            }
            $this->UpdateOrCreateRecord('transactions', $TransactionData);
        }
        $List = app(TenantSavingsAccountService::class);

        return $List->memberAccountList();
    }

    public function isNewAccount()
    {
        // this  is shared method
        $req = request()->all();
        request()->validate([
            'member' => ['nullable', 'exists:tenant.members,id'],
            'product_id' => ['required', 'exists:tenant.savings_products,id'],
            'in_deposit' => ['nullable', 'numeric', 'min:0'],
            'new_account' => ['required', 'in:1,depositing,0'],
            'status' => ['nullable', 'in:active,dormant,closed'],
        ]);
        // return $req['code'];
        $codeSequence = new CodeSequence;
        $settings = new FindsettingsAction(null);
        $createTransactionAlso = null;
        $chargedAmount = null;

        $checkfrTheproduct = DB::table('savings_products')->where('id', $req['product_id'])->first(['minimum_balance', 'id', 'name']);
        $checkIfHaveSomeAccounts = DB::table('savings_accounts')->where('member_id', $req['member'])->first(['id']);
        $NeededMinAmount = number_format($checkfrTheproduct->minimum_balance, 2);
        $deposit = isset($req['in_deposit']) ? (float) $req['in_deposit'] : 0;
        $consinderMinBalance=$settings->saccoSavingsAccountsConsiderMinimumBalance();

        if (($checkfrTheproduct && $consinderMinBalance && isset($checkIfHaveSomeAccounts) && $checkIfHaveSomeAccounts->id) && $deposit < (float) $NeededMinAmount) {
            // / error  shows the min amount required
            return $this->amountError($NeededMinAmount);
        }

        $listCharges = ['deposit' => ['deposited_amount_before_charge' => $req['in_deposit'], 'code' => $req['transaction_reference'] ?? null, 'amount' => $deposit, 'narration' => 'Initial deposit', 'transaction_type' => 'deposit', 'type' => 'deposit']];

        if ($deposit > 0) {
            $createTransactionAlso = $settings->saccoAccountOnAccountCreationShowInitialDeposit();
            // / let take off the charges $req['new_account']
            if ($req['new_account'] == 1) {
                request()->merge(['type' => 'deposit', 'amount' => $deposit]);
                $caller = new ProductChargesservice;
                $getTheProductCharges = $caller->productCharges();
                $chargedAmount = $getTheProductCharges->cost;
            }
            $deposit = $deposit - ($chargedAmount);
            if ($chargedAmount > 0)
                $listCharges['deposit_charges'] = ['charge_amount' => $chargedAmount, 'narration' =>  'Initial deposit charge: ' . $chargedAmount . ' blc :' . $deposit, 'transaction_type' => 'charge', 'type' => 'charge'];


            if (($createTransactionAlso) && $deposit < $NeededMinAmount) {
                // if (($createTransactionAlso == true || $createTransactionAlso == 1) && $deposit < $NeededMinAmount) {
                return $this->amountError($deposit, addtionalMessage: ' Minimum balance required is =' . $NeededMinAmount . ' for this product. deposit' . $deposit);
            }
        }

        $saccoAcountData = $this->savingAccountUOrCFields([...$req, 'blc' => $deposit ?? null,'cm_balance'=>$consinderMinBalance]);
        if (! isset($req['id'])) {
            $saccoAcountData['code'] = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-accounts', moduleTarget: 'savings-accounts', tableTaget: 'savings_accounts');
        }

        $saveAccountDetails = $this->UpdateOrCreateRecord('savings_accounts', $saccoAcountData);
        if (isset($deposit)) { // check it first  befor the next level save the RAM
            if (($createTransactionAlso == true || $createTransactionAlso == 1)) { // / check for the final level
                foreach ($listCharges as $chargeType => $information) {
                    $code = $codeSequence->codeSequence($information['code'] ?? null);
                    $TransactionData = $this->transactionUorCFields([
                        'reference' => $code,
                        'code' => $code,
                        'member' => $req['member'],
                        'deposited_amount_before_charge' => $information['deposited_amount_before_charge'] ?? null,
 
                        'transaction_type' => $information['transaction_type'],
                        'amount' => $information['amount'] ?? 0,
                        'charge_amount' => $information['charge_amount'] ?? null,
                        // 'payment_method' => 'cash',
                        'deposited_by' => 'System (Initial Deposit)',
                        'transaction_date' => now()->toDateString(),
                        'accid' => $saveAccountDetails->id,
                        'payment_mode_id' => $req['payment_mode_id'] ?? null,
                        'transaction_type' => $chargeType,
                        'narration' => $information['narration'] ?? null,
                        // 'b4trn' => $deposit ?? null, this affects the new accounts 

                    ]);
                    $this->UpdateOrCreateRecord('transactions', $TransactionData);
                }
            }
        }
        $List = app(TenantSavingsAccountService::class);

        return $List->memberAccountList();
    }

    /***
     * [
     * [code] => [account_id]
     * ]
     *
     * **/
    public function listMemeberAccountIds($codesList, $chunk = 300)
    {
        $list = [];
        $getAccountCode = array_chunk(array_unique(array_column($codesList, 'account_code')), $chunk);
        foreach ($getAccountCode as $key => $value) {
            $collections = DB::table('savings_accounts')->whereIn('code', $value)->get(['id', 'code']);
            foreach ($collections as $key2 => $value2) {
                $list[$value2->code] = $value2->id;
            }
        }

        return $list;
    }

    /***
     * [
     * [code] => [member_id]
     * ]
     *
     * **/
    public function listMemberIds($codesList, $chunk = 300, $key = 'code')
    {
        $list = [];
        $getAccountCode = array_chunk(array_unique(array_column($codesList, $key)), $chunk);
        foreach ($getAccountCode as $key => $value) {
            $collections = DB::table('members')->whereIn('code', $value)->get(['id', 'code']);
            foreach ($collections as $key2 => $value2) {
                $list[$value2->code] = $value2->id;
            }
        }

        return $list;
    }

    public function listsavingsGroupsds($codesList, $chunk = 300, $key = 'code')
    {
        $list = [];
        $getAccountCode = array_chunk(array_unique(array_column($codesList, $key)), $chunk);
        foreach ($getAccountCode as $key => $value) {
            $collections = DB::table('savings_groups')->whereIn('code', $value)->get(['id', 'code']);
            foreach ($collections as $key2 => $value2) {
                $list[$value2->code] = $value2->id;
            }
        }

        return $list;
    }

    public function listLoanIds($codesList, $chunk = 300, $key = 'loan_no')
    {
        $list = [];
        $getAccountCode = array_chunk(array_unique(array_column($codesList, $key)), $chunk);
        foreach ($getAccountCode as $key => $value) {
            $collections = DB::table('Loans')->whereIn('code', $value)->get(['id', 'loan_no AS code']);
            foreach ($collections as $key2 => $value2) {
                $list[$value2->code] = $value2->id;
            }
        }

        return $list;
    }

    /***
     * [
     * [code] => [id]
     * ]
     *
     * **/
    public function listproductIds($codesList, $chunk = 300, $key = 'product_code')
    {
        $defaultProduct = 'General Savings Account';
        $list = [];
        $getAccountCode = array_chunk(array_unique(array_column($codesList, $key)), $chunk);
        foreach ($getAccountCode as $key => $value) {
            $collections = DB::table('savings_products')
                ->whereIn('code', $value)
                ->orWhere('name', $defaultProduct)
                ->get(['id', 'code', 'name']);
            foreach ($collections as $key2 => $value2) {
                if ($value2->name == $defaultProduct) {
                    $list['default'] = $value2->id;
                } else {
                    $list[$value2->code] = $value2->id;
                }
            }
        }

        return $list;
    }

    /***
     * [
     * [code] => [branch_id]
     * ]
     *
     * **/
    public function listStaffIds()
    {
        $list = [];
        $collections = DB::table('staff')->get(['id', 'code']);
        foreach ($collections as $key2 => $value2) {
            $list[$value2->code] = $value2->id;
        }

        return $list;
    }

    public function listBranchIds()
    {
        $list = [];
        $collections = DB::table('branches')->get(['id', 'code']);
        foreach ($collections as $key2 => $value2) {
            $list[$value2->code] = $value2->id;
        }

        return $list;
    }

    public function LoanApplicationProductIds()
    {
        $list = [];
        $collections = DB::table('loan_products')->get(['id', 'code']);
        foreach ($collections as $key2 => $value2) {
            $list[$value2->code] = $value2->id;
        }

        return $list;
    }
    /***
     *
     *
     * **/

    public function saveMigrationsFiles()
    {
        // dd(request()->file('file'));
        // $file, $folderPath = 'uploads', $fileName = '', $type = 'file'
        return $this->saveFile('file', 'migrations-import-files');
    }
}
