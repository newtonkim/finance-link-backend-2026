<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Jobs\SendQueuedNotificationsAndMessages;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAcountServices\CrudHelders;
use Illuminate\Support\Facades\DB;

class TenantLoanUpdateOrCreateService extends GlobalHelpers
{
    public function loanTransactionsUploadTemplate()
    {
        $CrudHelders = new CrudHelders;
        $req = request()->all();
        $failed = [];
        $chunks = 400; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $chuckRow = array_chunk($collection->rows, $chunks);
        // $memberCodedList = $CrudHelders->listMemberIds($collection->rows, key: 'member_code');
        $listLoanIds = $CrudHelders->listLoanIds($collection->rows);
        $CrudHelders->saveMigrationsFiles();
        $i = 0;
        foreach ($chuckRow as $chunkIndex => &$chunkIds) {
            foreach ($chunkIds as &$value) { // pass by reference
                $i++;
                // $memberId = $memberCodedList[$value->member_code] ?? null;
                $listLoanIds = $memberCodedList[$value->loan_no] ?? null;
                $codeSequence = new CodeSequence;
                $coderef = $codeSequence->codeSequence($value->loan_code ?? null, type: 'loan-application', moduleTarget: 'loan-application', tableTaget: 'loan_applications');
                $requiredFields = ['payment_method', 'payment_date', 'amount_paid', 'payment_date'];
                foreach ($requiredFields as $key) {
                    $v = $value->$key ?? null;
                    if (empty($v)) {
                        if (! isset($failed[$i])) {
                            $failed[$i] = [
                                'failed-row' => $i,
                                ...(array) $value,
                                'reason' => [],
                            ];
                        }
                        $failed[$i]['reason'][] = "Missing {$key}";
                    }
                }

                if (! isset($failed[$i])) {
                    $fields = [
                        'payment_id' => $value->payment_reference ?? null,
                        'loan_id' => $listLoanIds ?? null,
                        'reschedule_id' => $value->reschedule_id ?? null,
                        'amount_paid' => $value->amount_paid ?? null,
                        'principal_portion' => $value->principal_portion ?? null,
                        'interest_portion' => $value->interest_portion ?? null,
                        'penalty_portion' => $value->penalty_portion ?? null,
                        'charges_portion' => $value->charges_portion ?? null,
                        'payment_date' => $value->payment_date ?? null,
                        'payment_method' => $value->payment_method ?? null,
                        'receipt_no' => $value->receipt_no ?? null,
                        'collected_by' => $value->collected_by ?? null,
                        'reversal_flag' => $value->reversal_flag ?? null,
                        'reversed_by' => $value->reversed_by ?? null,
                        'reversed_date' => $value->reversed_date ?? null,
                        'transaction_ref' => $value->transaction_ref ?? null,
                        'created_at' => $value->created_at ?? now(),
                    ];
                    $check = $this->UpdateOrCreateRecord('loan_repayment_schedule', $this->removeAllNullValues($fields));
                    if (! $check) {
                        $failed[$i] = ['failed-row' => $i, ...(array) $value];
                    }
                }
            }
        }

        return $failed;
    }

    public function loanRepaymentUploadTemplate()
    {
        $CrudHelders = new CrudHelders;
        $req = request()->all();
        $failed = [];
        $chunks = 400; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $chuckRow = array_chunk($collection->rows, $chunks);
        $memberCodedList = $CrudHelders->listMemberIds($collection->rows, key: 'member_code');
        $listLoanIds = $CrudHelders->listLoanIds($collection->rows);
        $CrudHelders->saveMigrationsFiles();
        $i = 0;
        foreach ($chuckRow as $chunkIndex => &$chunkIds) {
            foreach ($chunkIds as &$value) { // pass by reference
                $i++;
                $memberId = $memberCodedList[$value->member_code] ?? null;
                $listLoanIds = $memberCodedList[$value->loan_no] ?? null;
                $codeSequence = new CodeSequence;
                $coderef = $codeSequence->codeSequence($value->loan_code ?? null, type: 'loan-application', moduleTarget: 'loan-application', tableTaget: 'loan_applications');
                $requiredFields = ['member_code', 'loan_no', 'paid_at', 'outstanding_balance'];
                foreach ($requiredFields as $key) {
                    $v = $value->$key ?? null;
                    if (empty($v)) {
                        if (! isset($failed[$i])) {
                            $failed[$i] = [
                                'failed-row' => $i,
                                ...(array) $value,
                                'reason' => [],
                            ];
                        }
                        $failed[$i]['reason'][] = "Missing {$key}";
                    }
                }

                if (! isset($failed[$i])) {
                    $fields = [
                        'member_id' => $memberId,
                        'loan_id' => $listLoanIds ?? null,
                        'code' => $coderef ?? null,
                        'installment_no' => $value->installment_no ?? null,
                        'paid_at' => $value->paid_at ?? null,
                        'principal_due' => $value->principal_due ?? null,
                        'interest_due' => $value->interest_due ?? null,
                        'charges_due' => $value->charges_due ?? null,
                        'penalty_due' => $value->penalty_due ?? null,
                        'total_due' => $value->total_due ?? null,
                        'principal_paid' => $value->principal_paid ?? null,
                        'interest_paid' => $value->interest_paid ?? null,
                        'charges_paid' => $value->charges_paid ?? null,
                        'penalty_paid' => $value->penalty_paid ?? null,
                        'outstanding_balance' => $value->outstanding_balance ?? null,
                        'status' => $value->status ?? null,
                        'due_date' => $value->due_date ?? null,
                        'created_at' => $value->created_at ?? now(),
                    ];
                    $check = $this->UpdateOrCreateRecord('loan_repayment_schedule', $this->removeAllNullValues($fields));
                    if (! $check) {
                        $failed[$i] = ['failed-row' => $i, ...(array) $value];
                    }
                }
            }
        }

        return $failed;
    }

    public function loanApplicationUploadTemplate()
    {
        //  this function is to upload loan application but not tested yet
        // if disbursted is true then disbursement date must be set/ and othere Db fields must be set
        $CrudHelders = new CrudHelders;
        $req = request()->all();
        $failed = [];
        $chunks = 300; // chunk size
        $collection = $this->isJSONToArray($req['collection']);
        $chuckRow = array_chunk($collection->rows, $chunks);
        $memberCodedList = $CrudHelders->listMemberIds($collection->rows, key: 'member_code');
        $branchList = $CrudHelders->listBranchIds($collection->rows);
        $productList = $CrudHelders->LoanApplicationProductIds($collection->rows);
        $listStaffIds = $CrudHelders->listStaffIds($collection->rows);
        $CrudHelders->saveMigrationsFiles();
        $i = 0;
        foreach ($chuckRow as $chunkIndex => &$chunkIds) {
            foreach ($chunkIds as &$value) { // pass by reference
                $i++;
                $memberId = $memberCodedList[$value->member_code] ?? null;
                $codeSequence = new CodeSequence;
                $coderef = $codeSequence->codeSequence($value->loan_code ?? null, type: 'loan-application', moduleTarget: 'loan-application', tableTaget: 'loan_applications');
                $requiredFields = ['member_code', 'requested_amount', 'requested_term'];
                foreach ($requiredFields as $key) {
                    $v = $value->$key ?? null;
                    if (empty($v)) {
                        if (! isset($failed[$i])) {
                            $failed[$i] = [
                                'failed-row' => $i,
                                ...(array) $value,
                                'reason' => [],
                            ];
                        }
                        $failed[$i]['reason'][] = "Missing {$key}";
                    }
                }

                if (! isset($failed[$i])) {
                    $fields = [
                        'member_id' => $memberId,
                        'branch_id' => $branchList[$value->branch_code] ?? null,
                        'application_no' => $coderef ?? null,
                        'requested_term' => $value->requested_term ?? null,
                        'repayment_source' => $value->repayment_source ?? null,
                        'status' => $value->status ?? 'draft',
                        'approved_term' => $value->approved_term ?? null,
                        'loan_officer_id' => $listStaffIds[$value->loan_officer_code] ?? null,
                        'requested_amount' => $value->requested_amount ?? null,
                        'submitted_at' => $value->submitted_at ?? null,
                        'recommended_amount' => $value->recommended_amount ?? null,
                        'approved_amount' => $value->approved_amount ?? $value->requested_amount,
                        'submitted_at' => $value->submitted_date ?? null,
                        'purpose' => $value->purpose ?? null,
                        'final_approved_amount' => $value->final_approved_amount ?? null,
                        'loan_product_id' => $productList[$value->product_code] ?? null,
                        'loan_duration' => $value->loan_duration ?? null,
                        'officer_notes' => $value->officer_notes ?? null,
                        'interest_method' => $value->interest_method ?? null,
                        'interest_period' => $value->interest_period ?? null,
                    ];
                    $check = $this->UpdateOrCreateRecord('loan_applications', $this->removeAllNullValues($fields));
                    if (strtolower($value->status) == 'disbursed') {
                        $this->xslImportInLoansTable($value, $productList, $listStaffIds, $check, $memberId);
                    }
                    if (! $check) {
                        $failed[$i] = ['failed-row' => $i, ...(array) $value];
                    }
                }
            }
        }

        return $failed;
    }

    public function xslImportInLoansTable($value, $productList, $listStaffIds, $check)
    {
        $codeSequence = new CodeSequence;
        $coderef = $codeSequence->codeSequence($value->loan_code ?? null, type: 'loan-application', moduleTarget: 'loan-application', tableTaget: 'loans');

        $check = $this->UpdateOrCreateRecord('loans', $this->removeAllNullValues([
            'loan_no' => $coderef,
            'member_id' => $check->member_id,
            'principal' => $check->approved_amount,
            'loan_application_id' => $check->id,
            'loan_product_id' => $productList[$value->product_code],
            'interest_rate' => $value->interest_rate ?? null,
            'term_months' => $value->term_months ?? 1,
            'status' => $value->status ?? null,
            'applied_amount' => $value->requested_amount ?? null,
            'disbursed_at' => $value->disbursement_date ?? null,
            'disbursement_amount' => $value->approved_amount ?? null,
            'loan_disbursed_by' => $listStaffIds[$value->disbursed_by_code] ?? null,
            'disbursed_by' => $listStaffIds[$value->disbursed_by_code] ?? null,
            'loan_purpose' => $listStaffIds[$value->purpose] ?? null,

        ]));
    }

    public function saveGuarantorsNoneMember()
    {

        $req = request()->all();

        return $this->transaction(function () use ($req) {
            $MemberUpdateOrCreateService = new MemberUpdateOrCreateService;
            $codeSequence = new CodeSequence;
            $isExisting = false;
            $settings = new FindsettingsAction(null);
            $dataField = $MemberUpdateOrCreateService->mememberUOrCFields($req);
            $saveTwoAccounts = $settings->saccoMemberSaveAndSavingAccountAtOnce();
            $code = $codeSequence->codeSequence($req['code'] ?? null, 'members', 'members-onboarding', 'members');
            $dataField['member_number'] = $code;
            $dataField['code'] = $code;
            $dataField['created_from'] = 'loan-Guarantors';
            $dataField['status'] = $settings->saccoMemberRequireApprovalBeforeMemberBecomesActive();
            $createTransactionAlso = $settings->saccoAccountOnAccountCreationShowInitialDeposit();
            $dataField['password'] = $this->memberDefaultPassword($dataField['code']);
            $guarantor = $this->UpdateOrCreateRecord('members', $dataField);

            $chargedAmount = 0;
            $transactionDetails = [];
            $accountDetails = [];
            $deposit = (float) $guarantor->initial_deposit;
            if ($saveTwoAccounts) { // / create  a savings account for the member
                $saccoAcountData = [ // / values for the savings account for both
                    'member_id' => $guarantor->id,
                    'savings_product_id' => $req['product_id'] ?? null,
                    'account_type' => 'voluntary',
                    'is_new_account' => true,
                    'consider_min_balance' => true,
                    'status' => 'active',
                    'account_opening_balance' => $req['opening_balance'] ?? 0,
                    'code' => $codeSequence->codeSequence(type: 'savings-accounts', tableTaget: 'savings_accounts'),
                    'initial_deposit' => $deposit,
                ];

                $listCharges = ['deposit' => $deposit];

                if ($isExisting && ! empty($req['product_id'])) {
                } else {

                    $geTheGenericProductAcount = DB::table('savings_products')->where('name', 'General Savings Account')->first(['id']);
                    $saccoAcountData = [
                        ...$saccoAcountData,
                        'savings_product_id' => $geTheGenericProductAcount->id,
                    ];
                    if ($deposit > 0) {
                        $caller = new ProductChargesservice;
                        request()->merge(['type' => 'deposit', 'amount' => $deposit, 'product_id' => $saccoAcountData['savings_product_id'] ?? null]);
                        $getTheProductCharges = $caller->productCharges();
                        $chargedAmount = $getTheProductCharges->cost;
                        $deposit = $deposit - $chargedAmount;
                        $listCharges['deposit'] = 'Initial deposit charge: '.$chargedAmount.' blc :'.$deposit;
                        if ($createTransactionAlso && $deposit < 0) {
                            return $this->amountError($deposit);
                        }
                    }
                }

                if ($guarantor) {
                    $code = $codeSequence->codeSequence($req['code'] ?? null, 'staff');
                    $applicationId = $req['application_id'] ?? null;

                    $this->UpdateOrCreateRecord('loan_application_guarantors', [
                        'code' => $code,
                        'note' => $guarantor->note ?? null,
                        'guarantee_amount' => $deposit ?? null,
                        'loan_application_id' => $applicationId,
                        'guarantor_id' => $guarantor->member_id,
                        'guarantor_account_id' => $guarantor->account_id,
                        'guarantor_type' => 'individual',
                    ]);
                    $notify = new NotificationService;

                    $notify->runTheQue();
                    $guarantor->type = 'individual';

                    $this->determineGuarantorType('individual', $guarantor->id, $guarantor, $applicationId, $notify, false);
                }
                $CrudHelders = new CrudHelders;
                $saccoAcountData['balance'] = $isExisting ? $req['opening_balance'] : $deposit;
                $accountDetails = $this->UpdateOrCreateRecord('savings_accounts', $saccoAcountData);
                if (isset($deposit)) {
                    if (($createTransactionAlso == true || $createTransactionAlso == 1)) {
                        foreach ($listCharges as $chargeType => $information) {
                            $TransactionData = $CrudHelders->transactionUorCFields([
                                'reference' => 'SAC-IDP-'.date('Ymd').'-'.mt_rand(10000, 99999),
                                'member' => $guarantor->id,
                                'transaction_type' => $getTheProductCharges?->charge_type ?? null,
                                'amount' => $deposit,
                                'charge_amount' => $chargedAmount,
                                'payment_method' => 'cash',
                                'deposited_by' => 'System (Initial Deposit)',
                                'transaction_date' => now()->toDateString(),
                                'accid' => $accountDetails->id,
                                'transaction_type' => $chargeType,
                                'narration' => $information,
                            ]);
                        }
                        $transactionDetails[] = $this->UpdateOrCreateRecord('transactions', $TransactionData);
                    }
                }
            }
        });
    }

    public function determineGuarantorType($type, $id, $guarantor, $applicationId, $notify, $transactionInsert = true)
    {
        $getLoanApplication = DB::table('loan_applications')->where('loan_applications.id', $applicationId)
            ->join('members as mb', 'loan_applications.member_id', '=', 'mb.id')
            ->first(['application_no', 'mb.name', 'mb.code AS mCode']);
        if ($type == 'group') {
            $res = DB::table('savings_group_members')->whereRaw('savings_group_id=?', [$id])
                ->join('members as mb', 'savings_group_members.savings_group_id', '=', 'mb.id');
        } elseif ($type == 'individual') {
            $res = DB::table('members as mb')->where('id', '=', $id);
        }
        $sendMessage = $res->get(['mb.name', 'mb.code', 'mb.phone', 'mb.email', 'mb.id']);
        foreach ($sendMessage as $key => $value) {
            $body =
                $type == 'individual' ?
                "Hello {$value->name}, you have been added as a guarantor for loan application {$getLoanApplication->application_no}. The applicant is {$getLoanApplication->name}." :
                "Hello {$guarantor->name}, Members you have been added as a guarantor for loan application {$getLoanApplication->application_no}. The applicant is {$getLoanApplication->name}.";

            // $notify = new NotificationService();
            return $notify->sendNotification(
                'saccoNotifyTheGuarantor',
                $body,
                $value,
                [
                    'type' => $type,
                    'id' => $id,
                    'application_id' => $applicationId,
                    'guarantor_id' => $guarantor->id,
                    'guarantor_name' => $guarantor->name,
                    'guarantor_type' => $guarantor->type,
                ],
                'sms',
                'guarantor-notification',
                $transactionInsert,
                false
            );
        }
    }

    public function saveGuarantors()
    {

        return $this->transaction(function () {
            $req = request()->all();
            $applicationId = $req['application_id'] ?? null;
            $notify = new NotificationService;

            $guarantors = $req['guarantors'] ?? [];
            if (empty($applicationId) || empty($guarantors)) {
                return;
            }
            $codeSequence = new CodeSequence;
            $workedOnG = [];
            foreach ($guarantors as $item) {
                $guarantor = $this->isJSONToArray($item);
                if (! $guarantor || ! isset($guarantor->id)) {
                    continue;
                }
                $type = match ($guarantor->type ?? null) {
                    'group' => 'group',
                    'individual' => 'individual',
                    default => 'staff',
                };
                $code = $codeSequence->codeSequence($req['code'] ?? null, 'staff');
                $workedOnG[$guarantor->id] = $guarantor->id;
                $this->UpdateOrCreateRecord('loan_application_guarantors', [
                    'code' => $code,
                    'note' => $guarantor->note ?? null,
                    'guarantee_amount' => $guarantor->contribution ?? null,
                    'loan_application_id' => $applicationId,
                    // 'guarantor_id' => $guarantor->id,
                    'guarantor_id' => $guarantor->member_id,
                        'guarantor_account_id' => $guarantor->account_id,
                    'guarantor_type' => $type,
                ]);
                // / queue the message
                $this->determineGuarantorType($type, $guarantor->id, $guarantor, $applicationId, $notify);
            }
            $notify->runTheQue();
            // $subdomain = request()->header('X-Tenant-Subdomain');
            // SendQueuedNotificationsAndMessages::dispatch($subdomain);
        });
    }
}
