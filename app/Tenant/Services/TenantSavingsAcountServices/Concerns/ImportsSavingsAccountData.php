<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\TenantSavingsAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ImportsSavingsAccountData
{
    public function importGroups()
    {
        $req = request()->all();
        $failed = [];
        $workedOnIds = [];
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
                            'location' => $value->group_location ?? null,
                            'branch_id' => $branch_id,
                            'code' => $code,
                            'description' => $value->group_description ?? 'no destription during migration',
                        ];
                        $inThisInsetCheck = $this->UpdateOrCreateRecord('savings_groups', $workedOnIdsFields);
                        $details = $this->UpdateOrCreateRecord('group_savings_accounts', [
                            'savings_group_id' => $inThisInsetCheck->id,
                            'branch_id' => $branch_id,
                            'savings_product_id' => $savingProductList[$value->savings_product] ?? null,
                            'opening_balance' => $value->opening_balance ?? 0,
                            'initial_deposit' => $value->initial_deposit ?? 0,
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
        $workedOnIds = [];
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
                    // code...
                    if (isset($memberCodedList[$value->member_code])) {
                        $code = $codeSequence->codeSequence($req['code'] ?? null, type: 'savings-group', moduleTarget: 'savings-group', tableTaget: 'savings_groups');
                        $workedOnIdsFields = [
                            'member_id' => $memberCodedList[$value->member_code] ?? null,
                            'savings_group_id' => $listsavingsGroupsds[$value->group_code],
                            'role' => $value->role ?? 'member',
                            'branch_id' => $req['branch_id'],
                            'code' => $value->code ?? $code,

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

    public function importOpeningBalance()
    {
        $req = request()->all();
        $failed = [];
        $workedOnIds = [];
        $chunks = 300; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $idChunks = array_chunk($collection->rows, $chunks);
        foreach ($idChunks as $chunkIndex => &$chunkIds) { // pass chunk by reference
            $ids = array_column($chunkIds, 'account_number');
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
                                    'reference' => 'SAC-'.$codeSequence.'-'.time().'-'.mt_rand(10000000, 99999999),
                                    'member_id' => $getMemberId,
                                    'amount' => $value->amount,
                                    'payment_mode' => isset($value->method) ? $value->method : 'cash',
                                    'deposited_by' => 'System ('.$detaminTheType.' data Migration)',
                                    'type' => $detaminTheType.'_charges',
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
