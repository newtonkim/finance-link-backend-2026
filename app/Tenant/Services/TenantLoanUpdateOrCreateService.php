<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\MemebersSettingSevices\ProductChargesservice;
use App\Tenant\Services\TenantSavingsAcountServices\CrudHelders;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /**
     * Registers someone who is not yet a member, opens their savings account, and
     * records them as a guarantor. Only allowed while the "members only" guarantor
     * setting is off. The pledge goes through LoanGuarantorService like any other,
     * so capacity and the other guarantor rules still apply.
     */
    public function saveGuarantorsNoneMember()
    {
        $req = request()->all();
        $application = $this->guarantorApplication($req['application_id'] ?? null);
        $service = app(LoanGuarantorServiceInterface::class);

        if ($service->rules($application)->membersOnly) {
            throw ValidationException::withMessages([
                'guarantor' => ['Only active members may guarantee loans. Turn off "Members only" in the guarantor settings to record a non-member.'],
            ]);
        }

        $result = DB::connection('tenant')->transaction(function () use ($req, $application, $service) {
            $MemberUpdateOrCreateService = new MemberUpdateOrCreateService;
            $codeSequence = new CodeSequence;
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
            $member = $this->UpdateOrCreateRecord('members', $dataField);

            if (empty($member->id)) {
                throw ValidationException::withMessages([
                    'guarantor' => [$member->log_failure_reason ?? 'The guarantor could not be registered.'],
                ]);
            }

            $chargedAmount = 0;
            $getTheProductCharges = null;
            $accountDetails = null;
            $deposit = (float) $member->initial_deposit;

            if ($saveTwoAccounts) {
                $generalProduct = DB::table('savings_products')->where('name', 'General Savings Account')->first(['id']);
                $saccoAcountData = [
                    'member_id' => $member->id,
                    'savings_product_id' => $generalProduct?->id,
                    'account_type' => 'voluntary',
                    'is_new_account' => true,
                    'consider_min_balance' => true,
                    'status' => 'active',
                    'account_opening_balance' => $req['opening_balance'] ?? 0,
                    'code' => $codeSequence->codeSequence(type: 'savings-accounts', tableTaget: 'savings_accounts'),
                    'initial_deposit' => $deposit,
                ];

                $listCharges = ['deposit' => $deposit];

                if ($deposit > 0) {
                    $caller = new ProductChargesservice;
                    request()->merge(['type' => 'deposit', 'amount' => $deposit, 'product_id' => $saccoAcountData['savings_product_id']]);
                    $getTheProductCharges = $caller->productCharges();
                    $chargedAmount = $getTheProductCharges->cost;
                    $deposit = $deposit - $chargedAmount;
                    $listCharges['deposit'] = 'Initial deposit charge: '.$chargedAmount.' blc :'.$deposit;
                    if ($createTransactionAlso && $deposit < 0) {
                        // Thrown rather than returned so the member created above rolls back.
                        throw ValidationException::withMessages(['opening_balance' => [$this->amountError($chargedAmount)['message']]]);
                    }
                }

                $saccoAcountData['balance'] = $deposit;
                $accountDetails = $this->UpdateOrCreateRecord('savings_accounts', $saccoAcountData);

                if ($createTransactionAlso == true || $createTransactionAlso == 1) {
                    $CrudHelders = new CrudHelders;
                    foreach ($listCharges as $chargeType => $information) {
                        $TransactionData = $CrudHelders->transactionUorCFields([
                            'reference' => 'SAC-IDP-'.date('Ymd').'-'.mt_rand(10000, 99999),
                            'member' => $member->id,
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
                    $this->UpdateOrCreateRecord('transactions', $TransactionData);
                }
            }

            // The account has to exist before the pledge: the guarantor's capacity is
            // worked out from their savings balance.
            $pledge = $service->addGuarantor(
                application: $application,
                type: LoanApplicationGuarantor::TYPE_INDIVIDUAL,
                guarantorId: (int) $member->id,
                accountId: $accountDetails->id ?? null,
                amount: (float) ($req['guarantee_amount'] ?? $deposit),
                note: $req['note'] ?? null,
                actorId: auth()->id(),
            );

            return ['pledge' => $pledge, 'member' => $member];
        });

        return $service->summary($application);
    }

    /**
     * Saves the guarantors picked on the loan application screen. Each one goes
     * through LoanGuarantorService, so the guarantor rules are enforced and a
     * guarantor already on the application has their pledge updated, not duplicated.
     * Either every guarantor in the request is saved or none is.
     */
    public function saveGuarantors()
    {
        $req = request()->all();
        $application = $this->guarantorApplication($req['application_id'] ?? null);
        $items = $req['guarantors'] ?? [];

        if (empty($items)) {
            throw ValidationException::withMessages(['guarantors' => ['Pick at least one guarantor to save.']]);
        }

        $service = app(LoanGuarantorServiceInterface::class);
        $actorId = auth()->id();

        DB::connection('tenant')->transaction(function () use ($items, $application, $service, $actorId) {
            foreach ($items as $item) {
                $picked = $this->isJSONToArray($item);
                $picked = is_array($picked) ? (object) $picked : $picked;

                if (! is_object($picked)) {
                    continue;
                }

                [$type, $guarantorId, $accountId] = $this->pickedGuarantorIdentity($picked);

                try {
                    $service->addGuarantor(
                        application: $application,
                        type: $type,
                        guarantorId: $guarantorId,
                        accountId: $accountId,
                        amount: (float) ($picked->contribution ?? $picked->guarantee_amount ?? 0),
                        note: $picked->note ?? null,
                        actorId: $actorId,
                    );
                } catch (ValidationException $e) {
                    // Name the guarantor, since the request may carry several.
                    $name = $picked->name ?? 'A guarantor';
                    throw ValidationException::withMessages(collect($e->errors())
                        ->map(fn ($messages) => array_map(fn ($m) => str_contains($m, $name) ? $m : "{$name}: {$m}", $messages))
                        ->all());
                }
            }
        });

        return $service->summary($application);
    }

    private function guarantorApplication($applicationId): LoanApplication
    {
        $application = $applicationId ? LoanApplication::find($applicationId) : null;

        if (! $application) {
            throw ValidationException::withMessages(['application_id' => ['That loan application does not exist.']]);
        }

        return $application;
    }

    /**
     * The picker sends two shapes. A member row from the member/account dropdown has
     * id = the savings account id and member_id = the member. A group row has id = the
     * group, plus account_id when it came from the group account dropdown.
     *
     * @return array{0: string, 1: int, 2: ?int} type, guarantor id, account id
     */
    private function pickedGuarantorIdentity(object $picked): array
    {
        $type = $picked->type ?? null;
        $accountId = isset($picked->account_id) ? (int) $picked->account_id : null;

        if ($type === LoanApplicationGuarantor::TYPE_GROUP && ! empty($picked->id)) {
            return [$type, (int) $picked->id, $accountId];
        }

        if ($type === LoanApplicationGuarantor::TYPE_INDIVIDUAL && ! empty($picked->member_id)) {
            return [$type, (int) $picked->member_id, $accountId];
        }

        throw ValidationException::withMessages([
            'guarantors' => [($picked->name ?? 'A guarantor').' could not be identified. Pick them again from the list.'],
        ]);
    }
}
