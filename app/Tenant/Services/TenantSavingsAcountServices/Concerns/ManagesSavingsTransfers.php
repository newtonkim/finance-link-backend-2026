<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\TenantSavingsAccountService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait ManagesSavingsTransfers
{
    private function normalizeMemberIds($members): array
    {
        if (is_array($members)) {
            return array_values(array_filter($members, fn ($member) => $member !== null && $member !== ''));
        }

        return array_values(array_filter(explode(',', (string) $members), fn ($member) => $member !== null && $member !== ''));
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
}
