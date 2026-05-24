<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\LoanDisbursementServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanRepaymentServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LoanTopupService
{
    public function __construct(
        protected TopupEligibilityService $eligibilityService,
        protected LoanDisbursementServiceInterface $disbursementService,
        protected ScheduleGeneratorService $scheduleGenerator,
        protected LoanRepaymentServiceInterface $repaymentService,
    ) {}

    /**
     * Run the eligibility check for a top-up without executing it.
     */
    public function checkEligibility(
        Loan $referenceLoan,
        float $freshCashAmount,
        int $requestedTerm,
        string $topupType = 'consolidated',
    ): array {
        $result = $this->eligibilityService->evaluate(
            $referenceLoan,
            $freshCashAmount,
            $requestedTerm,
            $topupType,
        );

        return [
            'eligible' => $result->eligible,
            'passed' => $result->passed,
            'failed' => $result->failed,
            'warnings' => $result->warnings,
            'max_fresh_cash' => $result->maxEligibleAmount,
            'projected_total' => $topupType === 'consolidated'
                ? $referenceLoan->getTotalOutstandingAmount() + $freshCashAmount
                : $freshCashAmount,
        ];
    }

    /**
     * Execute the top-up: either auto-disburse or create an application.
     *
     * Returns the newly created Loan (auto) or LoanTopupApplication (standard).
     */
    public function execute(
        Loan $referenceLoan,
        float $freshCashAmount,
        int $requestedTerm,
        string $topupType,
        int $staffId,
    ): array {
        $branchId = $referenceLoan->branch_id;
        $settings = LoanSetting::currentForBranch($branchId);
        $product = $referenceLoan->loanProduct;

        $autoDisburse = $product->topup_auto_disbursement ?? $settings->topup_auto_disbursement ?? false;

        $newLoanTotal = $topupType === 'consolidated'
            ? $referenceLoan->getTotalOutstandingAmount() + $freshCashAmount
            : $freshCashAmount;

        if ($autoDisburse) {
            return $this->executeExpressFlow(
                $referenceLoan,
                $newLoanTotal,
                $requestedTerm,
                $topupType,
                $staffId,
            );
        }

        return $this->executeStandardFlow(
            $referenceLoan,
            $freshCashAmount,
            $newLoanTotal,
            $requestedTerm,
            $topupType,
            $staffId,
        );
    }

    /**
     * Express Flow: Auto-disburse. Close old loan and create + disburse a new one immediately.
     */
    protected function executeExpressFlow(
        Loan $referenceLoan,
        float $newLoanTotal,
        int $requestedTerm,
        string $topupType,
        int $staffId,
    ): array {
        return DB::connection('tenant')->transaction(function () use (
            $referenceLoan, $newLoanTotal, $requestedTerm, $topupType, $staffId
        ) {
            // 1. Close the old loan (mark as restructured and record payoff)
            if ($topupType === 'consolidated') {
                $payoffAmount = $referenceLoan->getTotalOutstandingAmount();

                if ($payoffAmount > 0) {
                    $this->repaymentService->post(
                        $referenceLoan,
                        $payoffAmount,
                        [
                            'payment_date' => now()->toDateString(),
                            'payment_method' => 'Top-up Consolidation',
                            'receipt_no' => 'TU-'.strtoupper(Str::random(6)),
                            'transaction_ref' => 'Consolidated into new loan',
                        ],
                        $staffId
                    );
                }

                // Mark old loan as closed — balance fully absorbed into new loan
                $referenceLoan->update(['status' => LoanStatus::Closed]);
            }

            // 2. Create the new loan
            $newLoan = Loan::create([
                'loan_no' => 'LN-'.strtoupper(Str::random(8)),
                'member_id' => $referenceLoan->member_id,
                'loan_product_id' => $referenceLoan->loan_product_id,
                'principal' => $newLoanTotal,
                'outstanding_balance' => $newLoanTotal,
                'interest_rate' => $referenceLoan->interest_rate,
                'term_months' => $requestedTerm,
                'status' => LoanStatus::Disbursed,
                'disbursed_at' => now(),
                'schedule_date' => now(),
                'branch_id' => $referenceLoan->branch_id,
                'loan_officer_id' => $referenceLoan->loan_officer_id,
                'disbursed_by' => $staffId,
                'parent_loan_id' => $referenceLoan->id,
                'topup_type' => $topupType,
                'charge_deduction_mode' => $referenceLoan->charge_deduction_mode ?? 'deduct_from_principal',
            ]);

            // 3. Generate repayment schedule for the new loan
            $this->disbursementService->regenerateSchedule($newLoan, Carbon::parse($newLoan->schedule_date));

            // 4. Post disbursement journal entry for the new loan
            $this->disbursementService->postTopupDisbursementEntry($newLoan, $staffId);

            // 5. Create the topup application record for audit
            DB::connection('tenant')->table('loan_topup_applications')->insert([
                'application_number' => 'TU-'.strtoupper(Str::random(8)),
                'member_id' => $referenceLoan->member_id,
                'reference_loan_id' => $referenceLoan->id,
                'new_loan_id' => $newLoan->id,
                'topup_type' => $topupType,
                'topup_amount' => $newLoanTotal - $referenceLoan->getTotalOutstandingAmount(),
                'new_loan_total' => $newLoanTotal,
                'requested_term' => $requestedTerm,
                'new_monthly_installment' => $newLoanTotal / $requestedTerm,
                'eligibility_passed' => true,
                'status' => 'disbursed',
                'branch_id' => $referenceLoan->branch_id,
                'created_by' => $staffId,
                'approved_by' => $staffId,
                'submitted_at' => now(),
                'approved_at' => now(),
                'disbursed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'flow' => 'express',
                'message' => 'Top-up disbursed successfully. Old loan has been closed.',
                'new_loan_id' => $newLoan->id,
                'new_loan_no' => $newLoan->loan_no,
            ];
        });
    }

    /**
     * Standard Flow: Create a loan top-up application that requires approval.
     */
    protected function executeStandardFlow(
        Loan $referenceLoan,
        float $freshCashAmount,
        float $newLoanTotal,
        int $requestedTerm,
        string $topupType,
        int $staffId,
    ): array {
        return DB::connection('tenant')->transaction(function () use (
            $referenceLoan, $freshCashAmount, $newLoanTotal, $requestedTerm, $topupType, $staffId
        ) {
            // 1. Close old loan (consolidated only)
            if ($topupType === 'consolidated') {
                $payoffAmount = $referenceLoan->getTotalOutstandingAmount();
                if ($payoffAmount > 0) {
                    $this->repaymentService->post(
                        $referenceLoan,
                        $payoffAmount,
                        [
                            'payment_date' => now()->toDateString(),
                            'payment_method' => 'Top-up Consolidation',
                            'receipt_no' => 'TU-'.strtoupper(Str::random(6)),
                            'transaction_ref' => 'Consolidated into new application',
                        ],
                        $staffId
                    );
                }
                $referenceLoan->update(['status' => LoanStatus::Closed]);
            }

            // 2. Create a proper LoanApplication (draft)
            $applicationNo = 'APP-TU-'.strtoupper(Str::random(8));
            $application = LoanApplication::create([
                'application_no' => $applicationNo,
                'member_id' => $referenceLoan->member_id,
                'loan_product_id' => $referenceLoan->loan_product_id,
                'branch_id' => $referenceLoan->branch_id,
                'loan_officer_id' => $referenceLoan->loan_officer_id,
                'requested_amount' => $newLoanTotal,
                'requested_term' => $requestedTerm,
                'purpose' => "Top-up ({$topupType}) from Loan #{$referenceLoan->loan_no}",
                'status' => LoanApplication::STATUS_DRAFT,
                'created_by' => $staffId,
            ]);

            // 3. Create the topup application record for audit
            $applicationNumber = 'TU-'.strtoupper(Str::random(8));
            DB::connection('tenant')->table('loan_topup_applications')->insert([
                'application_number' => $applicationNumber,
                'member_id' => $referenceLoan->member_id,
                'reference_loan_id' => $referenceLoan->id,
                'topup_type' => $topupType,
                'topup_amount' => $freshCashAmount,
                'new_loan_total' => $newLoanTotal,
                'requested_term' => $requestedTerm,
                'new_monthly_installment' => $newLoanTotal / $requestedTerm,
                'eligibility_passed' => true,
                'status' => 'application_created',
                'loan_application_id' => $application->id,
                'branch_id' => $referenceLoan->branch_id,
                'created_by' => $staffId,
                'submitted_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'flow' => 'standard',
                'message' => 'Top-up application created as draft. It will follow the standard approval process.',
                'application_id' => $application->id,
                'application_number' => $applicationNo,
            ];
        });
    }
}
