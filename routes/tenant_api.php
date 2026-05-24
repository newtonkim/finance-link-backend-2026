<?php

// routes/tenant_api.php — All tenant-scoped API routes

use App\Http\Globals\GlobalHelpers;
use App\Tenant\Http\Controllers\Api\V1\AccountingPeriodController;
use App\Tenant\Http\Controllers\Api\V1\BranchController;
use App\Tenant\Http\Controllers\Api\V1\ChartOfAccountController;
use App\Tenant\Http\Controllers\Api\V1\CurrencySettingsController;
use App\Tenant\Http\Controllers\Api\V1\DashboardController;
use App\Tenant\Http\Controllers\Api\V1\DocumentTypeController;
use App\Tenant\Http\Controllers\Api\V1\ExpenseBudgetController;
use App\Tenant\Http\Controllers\Api\V1\ExpenseCategoryController;
use App\Tenant\Http\Controllers\Api\V1\ExpenseController;
use App\Tenant\Http\Controllers\Api\V1\ExpenseThresholdController;
use App\Tenant\Http\Controllers\Api\V1\FinancialYearController;
use App\Tenant\Http\Controllers\Api\V1\FixedDepositController;
use App\Tenant\Http\Controllers\Api\V1\GeneralChargeController;
use App\Tenant\Http\Controllers\Api\V1\JournalEntryController;
use App\Tenant\Http\Controllers\Api\V1\LoanApplicationController;
use App\Tenant\Http\Controllers\Api\V1\LoanAppraisalController;
use App\Tenant\Http\Controllers\Api\V1\LoanApprovalController;
use App\Tenant\Http\Controllers\Api\V1\LoanArrearsTierController;
use App\Tenant\Http\Controllers\Api\V1\LoanChargeController;
use App\Tenant\Http\Controllers\Api\V1\LoanCollateralController;
use App\Tenant\Http\Controllers\Api\V1\LoanCommitteeController;
use App\Tenant\Http\Controllers\Api\V1\LoanController;
use App\Tenant\Http\Controllers\Api\V1\LoanDisbursementController;
use App\Tenant\Http\Controllers\Api\V1\LoanDocumentController;
use App\Tenant\Http\Controllers\Api\V1\LoanProductController;
use App\Tenant\Http\Controllers\Api\V1\LoanRepaymentController;
use App\Tenant\Http\Controllers\Api\V1\LoanSettingsController;
use App\Tenant\Http\Controllers\Api\V1\LoanTopupController;
use App\Tenant\Http\Controllers\Api\V1\MemberChargeController;
use App\Tenant\Http\Controllers\Api\V1\MemberController;
use App\Tenant\Http\Controllers\Api\V1\MemberLoanSummaryController;
use App\Tenant\Http\Controllers\Api\V1\MemberStatementController;
use App\Tenant\Http\Controllers\Api\V1\SavingsAccountStatementController;
use App\Tenant\Http\Controllers\Api\V1\MigrationController;
use App\Tenant\Http\Controllers\Api\V1\OnboardingSettingsController;
use App\Tenant\Http\Controllers\Api\V1\PublicHolidayController;
use App\Tenant\Http\Controllers\Api\V1\RegularSavingsInterestController;
use App\Tenant\Http\Controllers\Api\V1\ReportsController;
use App\Tenant\Http\Controllers\Api\V1\SaccoBrandingController;
use App\Tenant\Http\Controllers\Api\V1\SavingsAccountController;
use App\Tenant\Http\Controllers\Api\V1\SavingsGroupController;
use App\Tenant\Http\Controllers\Api\V1\SavingsProductController;
use App\Tenant\Http\Controllers\Api\V1\SavingsSharesBalancesController;
use App\Tenant\Http\Controllers\Api\V1\SavingsTransferController;
use App\Tenant\Http\Controllers\Api\V1\SharesController;
use App\Tenant\Http\Controllers\Api\V1\TenantSettingsController;
use App\Tenant\Http\Controllers\Api\V1\TenantStaffController;
use App\Tenant\Http\Controllers\Api\V1\TransactionController;
use App\Tenant\Http\Controllers\Api\V1\TransactionReversalController;
use App\Tenant\Http\Controllers\Api\V1\TrialBalanceController;
use Illuminate\Support\Facades\Route;

if (! function_exists('routeList')) {
    function routeList(array $routes, string $module, $particular)
    {
        // / i create this  befor add found out  that this is slower compare to routeListV2
        $fun = new GlobalHelpers;

        return $fun->routeList($routes, $module, $particular);
    }
}
if (! function_exists('routeListV2')) {
    function routeListV2(array $routes)
    {
        $fun = new GlobalHelpers;

        return $fun->routeListV2($routes);
    }
}

// Branches
Route::get('branches', [BranchController::class, 'index']);
Route::post('branches', [BranchController::class, 'store']);
Route::put('branches/{id}', [BranchController::class, 'update']);
Route::delete('branches/{id}', [BranchController::class, 'destroy']);
Route::patch('branches/{id}/toggle-active', [BranchController::class, 'toggleActive']);

// Dashboard metrics
Route::get('dashboard', [DashboardController::class, 'index']);
Route::get('reports', [ReportsController::class, 'index']);
Route::get('reports/filter-options', [ReportsController::class, 'filterOptions']);
Route::get('reports/member-statement/{member_id}', [MemberStatementController::class, 'show']);
Route::get('reports/savings-account-statement/{savingsAccountId}', [SavingsAccountStatementController::class, 'show'])
    ->whereNumber('savingsAccountId');
Route::get('reports/savings-shares-balances', [SavingsSharesBalancesController::class, 'index']);
Route::get('reports/loan-aging', [ReportsController::class, 'loanAging']);
Route::get('reports/loan-aging/portfolio-summary', [ReportsController::class, 'loanAgingPortfolioSummary']);
Route::get('reports/loan-balances', [ReportsController::class, 'loanBalances']);
Route::get('reports/loan-balances/export', [ReportsController::class, 'loanBalancesExport']);

// ── Loan Collections Report ────────────────────────────────────────────────
Route::get('reports/collections/summary', [ReportsController::class, 'collectionsSummary']);
Route::get('reports/collections/loans', [ReportsController::class, 'collectionsLoans']);
Route::get('reports/collections/export', [ReportsController::class, 'collectionsExport']);
Route::get('reports/collections/loans/{loan}/transactions', [ReportsController::class, 'collectionsLoanTransactions']);

// ── Loan Disbursement Report ───────────────────────────────────────────────
Route::get('reports/disbursements/summary', [ReportsController::class, 'disbursementSummary']);
Route::get('reports/disbursements/loans', [ReportsController::class, 'disbursementLoans']);
Route::get('reports/disbursements/trend', [ReportsController::class, 'disbursementTrend']);
Route::get('reports/disbursements/export', [ReportsController::class, 'disbursementExport']);

// ── Loan Arrears Report ─────────────────────────────────────────────────────
Route::get('reports/loan-arrears', [ReportsController::class, 'loanArrears']);
Route::get('reports/loan-arrears/comparison', [ReportsController::class, 'loanArrearsComparison']);
Route::get('reports/loan-arrears/trend', [ReportsController::class, 'loanArrearsTrend']);
Route::get('reports/loan-arrears/export', [ReportsController::class, 'loanArrearsExport']);
Route::get('reports/loan-arrears/{loan}/installments', [ReportsController::class, 'loanArrearsInstallments']);

// ── Trial Balance Report ────────────────────────────────────────────────────
Route::get('reports/trial-balance', [TrialBalanceController::class, 'index']);
Route::get('reports/trial-balance/ledger', [TrialBalanceController::class, 'ledger']);

// Members
Route::get('members/template', [MemberController::class, 'downloadTemplate']);
Route::post('members/import', [MemberController::class, 'import']);
Route::post('members/import-json', [MemberController::class, 'importJson']);
Route::resource('members', MemberController::class);
Route::post('members/{member}/avatar', [MemberController::class, 'updateAvatar']);
Route::put('members/{member}/approve', [MemberController::class, 'approve']);
Route::put('members/{member}/reject', [MemberController::class, 'reject']);
Route::put('members/{member}/requeue', [MemberController::class, 'requeue']);

// Member Loan Summary
Route::get('members/{member}/loan-summary', [MemberLoanSummaryController::class, 'show']);

// Member Charges (receivables)
Route::get('members/{member}/charges', [MemberChargeController::class, 'index']);
Route::post('members/{member}/charges/{memberCharge}/collect', [MemberChargeController::class, 'collect']);
Route::post('members/{member}/charges/{memberCharge}/waive', [MemberChargeController::class, 'waive']);

// Member Savings Accounts
Route::resource('savings-accounts', SavingsAccountController::class);
Route::post('savings-accounts/{savingsAccount}/deposit', [SavingsAccountController::class, 'deposit']);
Route::post('savings-accounts/{savingsAccount}/withdraw', [SavingsAccountController::class, 'withdraw']);
Route::post('savings-accounts/{savingsAccount}/charge', [SavingsAccountController::class, 'charge']);

// General charges (settings)
Route::get('general-charges', [GeneralChargeController::class, 'index']);
Route::post('general-charges', [GeneralChargeController::class, 'store']);
Route::put('general-charges/{generalCharge}', [GeneralChargeController::class, 'update']);
Route::patch('general-charges/{generalCharge}/toggle', [GeneralChargeController::class, 'toggle']);
Route::patch('general-charges/{generalCharge}/toggle-reversible', [GeneralChargeController::class, 'toggleReversible']);
Route::delete('general-charges/{generalCharge}', [GeneralChargeController::class, 'destroy']);
Route::put('savings-accounts/{savingsAccount}/custom-fees', [SavingsAccountController::class, 'updateCustomFees']);

// Savings Products
Route::apiResource('savings-products', SavingsProductController::class);

// Fixed Deposits
Route::get('savings/fixed-deposits', [FixedDepositController::class, 'index']);
Route::post('savings/fixed-deposits/post-interest', [FixedDepositController::class, 'postInterest']);
Route::post('savings/regular-interest/post-interest', [RegularSavingsInterestController::class, 'postInterest']);
Route::post('savings-accounts/{id}/maturity/process', [FixedDepositController::class, 'processMaturity']);
Route::get('savings-accounts/{id}/interest-postings', [FixedDepositController::class, 'interestPostings']);

// Loan Products
Route::get('document-types', [DocumentTypeController::class, 'index']);
Route::post('loan-products/preview', [LoanProductController::class, 'preview']);
Route::apiResource('loan-products', LoanProductController::class);
Route::apiResource('loan-charges', LoanChargeController::class);
Route::patch('loan-charges/{loanCharge}/toggle', [LoanChargeController::class, 'toggle']);

// Loan Applications
Route::get('loan-applications/summary', [LoanApplicationController::class, 'summary']);
Route::get('loan-applications/member-search', [LoanApplicationController::class, 'memberSearch']);
Route::post('loan-applications/eligibility-check', [LoanApplicationController::class, 'eligibilityCheck']);
Route::post('loan-applications/{loanApplication}/submit', [LoanApplicationController::class, 'submit']);
Route::post('loan-applications/{loanApplication}/cancel', [LoanApplicationController::class, 'cancel']);
Route::post('loan-applications/{loanApplication}/reopen', [LoanApplicationController::class, 'reopen']);
Route::get('loan-disbursements/pending', [LoanDisbursementController::class, 'pending']);
Route::post('loan-applications/{loanApplication}/disburse', [LoanDisbursementController::class, 'disburse']);

// ─── Active Loans & Repayments ────────────────────────────────────────────────
Route::get('loans/summary', [LoanController::class, 'summary']);
Route::get('loans/export', [LoanController::class, 'export']);
Route::get('loans', [LoanController::class, 'index']);
Route::get('loans/{id}', [LoanController::class, 'show']);
Route::get('loans/{id}/schedule', [LoanController::class, 'schedule']);
Route::get('loans/{id}/repayments', [LoanController::class, 'repayments']);
Route::get('loans/{id}/ledger', [LoanController::class, 'ledger']);
Route::get('loans/{id}/activities', [LoanController::class, 'activities']);
Route::post('loans/{id}/repayments/preview', [LoanRepaymentController::class, 'preview']);
Route::post('loans/{id}/repayments', [LoanRepaymentController::class, 'store']);
Route::post('loans/{id}/repayments/{transaction}/reverse', [LoanRepaymentController::class, 'reverse']);
Route::post('loans/{id}/reschedule/preview', [LoanController::class, 'reschedulePreview']);
Route::post('loans/{id}/reschedule', [LoanController::class, 'reschedule']);
Route::get('loans/{id}/reschedules', [LoanController::class, 'reschedules']);
Route::post('loans/{loan}/repay-from-savings', [LoanRepaymentController::class, 'repayFromSavings']);
Route::patch('loans/{loan}/update-dates', [LoanController::class, 'updateDates']);

// ── Loan Top-Up ────────────────────────────────────────────────────────────────
Route::post('loans/{loan}/topup/eligibility', [LoanTopupController::class, 'eligibility']);
Route::post('loans/{loan}/topup/execute', [LoanTopupController::class, 'execute']);
Route::post('loans/{loan}/write-off', [LoanController::class, 'writeOff']);

// Appraisal actions
Route::post('loan-applications/{loanApplication}/take-for-review', [LoanAppraisalController::class, 'takeForReview']);
Route::post('loan-applications/{loanApplication}/appraise', [LoanAppraisalController::class, 'appraise']);
Route::post('loan-applications/{loanApplication}/request-documents', [LoanAppraisalController::class, 'requestDocuments']);
Route::post('loan-applications/{loanApplication}/return-for-correction', [LoanAppraisalController::class, 'returnForCorrection']);
Route::post('loan-applications/{loanApplication}/resume-review', [LoanAppraisalController::class, 'resumeReview']);
Route::post('loan-applications/{loanApplication}/reject', [LoanAppraisalController::class, 'reject']);

// Three-tier committee approval actions
Route::post('loan-applications/{loanApplication}/bm-recommend', [LoanCommitteeController::class, 'bmRecommend']);
Route::post('loan-applications/{loanApplication}/committee/return-for-correction', [LoanCommitteeController::class, 'returnForCorrection']);
Route::post('loan-applications/{loanApplication}/votes', [LoanCommitteeController::class, 'castVote']);
Route::get('loan-applications/{loanApplication}/votes', [LoanCommitteeController::class, 'getVotes']);
Route::patch('loan-applications/{loanApplication}/votes/{staffId}/abstain', [LoanCommitteeController::class, 'markAbstention']);
Route::patch('loan-applications/{loanApplication}/confirm-terms', [LoanCommitteeController::class, 'confirmTerms']);
Route::get('loan-applications/{loanApplication}/proposed-schedule', [LoanCommitteeController::class, 'proposedSchedule']);
Route::get('loan-applications/{loanApplication}/proposed-schedule/export', [LoanCommitteeController::class, 'exportProposedSchedule']);

// Timeline
Route::get('loan-applications/{loanApplication}/timeline', [LoanApplicationController::class, 'timeline']);
// Approval actions
Route::get('loan-applications/{loanApplication}/approvals', [LoanApprovalController::class, 'index']);
Route::post('loan-applications/{loanApplication}/approve', [LoanApprovalController::class, 'approve']);
Route::post('loan-applications/{loanApplication}/decline', [LoanApprovalController::class, 'decline']);
Route::get('loan-applications/{loanApplication}/documents', [LoanDocumentController::class, 'index']);
Route::post('loan-applications/{loanApplication}/documents', [LoanDocumentController::class, 'store']);
Route::patch('loan-applications/{loanApplication}/documents/{document}', [LoanDocumentController::class, 'update']);
Route::delete('loan-applications/{loanApplication}/documents/{document}', [LoanDocumentController::class, 'destroy']);
Route::get('loan-applications/{loanApplication}/documents/{document}/download', [LoanDocumentController::class, 'download']);
Route::get('loan-applications/{loanApplication}/collaterals', [LoanCollateralController::class, 'index']);
Route::post('loan-applications/{loanApplication}/collaterals', [LoanCollateralController::class, 'store']);
Route::delete('loan-applications/{loanApplication}/collaterals/{collateral}', [LoanCollateralController::class, 'destroy']);
Route::apiResource('loan-applications', LoanApplicationController::class);

use App\Tenant\Http\Controllers\Api\V1\ExpenseReportController;

// Expenses
Route::prefix('expenses')->group(function () {
    Route::get('thresholds', [ExpenseThresholdController::class, 'index']);
    Route::get('thresholds/path', [ExpenseThresholdController::class, 'showPath']);
    Route::put('thresholds', [ExpenseThresholdController::class, 'update']);
    Route::get('budgets', [ExpenseBudgetController::class, 'index']);
    Route::get('budgets/check', [ExpenseBudgetController::class, 'check']);
    Route::post('budgets', [ExpenseBudgetController::class, 'store']);
    Route::get('stats', [ExpenseController::class, 'stats']);
    Route::get('categories', [ExpenseCategoryController::class, 'index']);
    Route::post('categories', [ExpenseCategoryController::class, 'store']);
    
    // Reports
    Route::prefix('reports')->group(function () {
        Route::get('ledger', [ExpenseReportController::class, 'ledger']);
        Route::get('variance', [ExpenseReportController::class, 'variance']);
        Route::get('unreconciled', [ExpenseReportController::class, 'unreconciled']);
        Route::get('branch-breakdown', [ExpenseReportController::class, 'byBranch']);
        Route::get('ie-extract', [ExpenseReportController::class, 'incomeExpenditureExtract']);
        Route::get('tb-contribution', [ExpenseReportController::class, 'trialBalanceContribution']);
    });
});

Route::put('expenses/{id}/approve', [ExpenseController::class, 'approve']);
Route::post('expenses/{id}/reject', [ExpenseController::class, 'reject']);
Route::post('expenses/{id}/query', [ExpenseController::class, 'query']);
Route::put('expenses/{id}/pay', [ExpenseController::class, 'pay']);
Route::apiResource('expenses', ExpenseController::class);

// Chart of Accounts
Route::resource('chart-of-accounts', ChartOfAccountController::class)->except(['create', 'edit', 'show']);

// Accounting Periods
Route::get('accounting-periods', [AccountingPeriodController::class, 'index']);
Route::post('accounting-periods/toggle', [AccountingPeriodController::class, 'toggleLock']);

// Journal Entries
Route::get('journal-entries/export', [JournalEntryController::class, 'export']);
Route::resource('journal-entries', JournalEntryController::class)->only(['index', 'store']);

// Savings Groups
Route::apiResource('savings-groups', SavingsGroupController::class);
Route::get('savings-groups/{savings_group}/members', [SavingsGroupController::class, 'members']);
Route::get('savings-groups/{savings_group}/members/export', [SavingsGroupController::class, 'exportMembers']);
Route::post('savings-groups/{savings_group}/members', [SavingsGroupController::class, 'addMember']);
Route::delete('savings-groups/{savings_group}/members/{member}', [SavingsGroupController::class, 'removeMember']);

// Staff Management routes moved down to avoid wildcard conflicts.

// Savings Transfer
Route::get('savings-transfer/accounts', [SavingsTransferController::class, 'accounts']);
Route::post('savings-transfer', [SavingsTransferController::class, 'store']);

// Onboarding Settings
Route::get('onboarding-settings', [OnboardingSettingsController::class, 'show']);
Route::put('onboarding-settings', [OnboardingSettingsController::class, 'update']);

// Loan Settings
Route::get('loan-settings', [LoanSettingsController::class, 'show']);
Route::put('loan-settings', [LoanSettingsController::class, 'update']);

Route::get('loan-arrears-tiers', [LoanArrearsTierController::class, 'index']);
Route::put('loan-arrears-tiers', [LoanArrearsTierController::class, 'bulkUpdate']);

// Public Holiday Settings
Route::get('public-holidays', [PublicHolidayController::class, 'index']);
Route::post('public-holidays', [PublicHolidayController::class, 'store']);
Route::put('public-holidays/settings', [PublicHolidayController::class, 'updateSettings']);
Route::put('public-holidays/{publicHoliday}', [PublicHolidayController::class, 'update']);
Route::delete('public-holidays/{publicHoliday}', [PublicHolidayController::class, 'destroy']);

// Currency Settings
Route::get('currencies', [CurrencySettingsController::class, 'currencies']);
Route::get('currency-settings', [CurrencySettingsController::class, 'show']);
Route::put('currency-settings', [CurrencySettingsController::class, 'update']);

// Financial Years
Route::apiResource('financial-years', FinancialYearController::class);

// Sacco Branding
Route::get('sacco-branding', [SaccoBrandingController::class, 'show']);
Route::post('sacco-branding', [SaccoBrandingController::class, 'update']);

// Transactions — legacy direct-reverse (kept for backwards compat, bypasses approval)
Route::post('transactions/{transaction}/reverse', [TransactionController::class, 'reverse']);

// Transaction Reversals (with approval workflow)
Route::post('transactions/{transaction}/request-reversal', [TransactionReversalController::class, 'store']);
Route::get('transaction-reversals', [TransactionReversalController::class, 'index']);
Route::post('transaction-reversals/{reversal}/approve', [TransactionReversalController::class, 'approve']);
Route::post('transaction-reversals/{reversal}/reject', [TransactionReversalController::class, 'reject']);

// Data Migration
Route::prefix('migration')->group(function () {
    Route::get('opening-balances/template', [MigrationController::class, 'downloadOpeningBalancesTemplate']);
    Route::post('opening-balances/import', [MigrationController::class, 'importOpeningBalances']);
    Route::post('opening-balances/import-json', [MigrationController::class, 'importOpeningBalancesJson']);
    Route::get('transactions/template', [MigrationController::class, 'downloadTransactionHistoryTemplate']);
    Route::post('transactions/import', [MigrationController::class, 'importTransactionHistory']);
    Route::post('transactions/import-json', [MigrationController::class, 'importTransactionHistoryJson']);
});

// RESTful Staff Management
Route::apiResource('staff', TenantStaffController::class);
Route::get('staff/{staff}/referred-members', [TenantStaffController::class, 'referredMembers']);
Route::post('staff/{staff}/avatar', [TenantStaffController::class, 'uploadAvatar']);

Route::group(['prefix' => '', 'middleware' => []], function () {

    Route::group(['prefix' => 'global/'], function () {
        Route::group(['controller' => ChartOfAccountController::class, 'middleware' => []], function () {
            routeListV2([
                [
                    'route' => 'chart-of-accounts',
                    'method' => 'chart_of_accounts_drop_down_list',
                ],
             
               
               
            ]);
        });
        Route::group(['controller' => LoanProductController::class, 'middleware' => []], function () {
            routeListV2([
                [
                    'route' => 'loan-products',
                    'method' => 'dropdown_list',
                ],
            ]);
        });
        Route::group(['controller' => MemberController::class, 'middleware' => []], function () {
            routeListV2([
                 [
                    'route' => 'member-saving-accounts-dropdown-list',
                    'method' => 'member_saving_accounts_drop_down_list',
                ],
                 [
                    'route' => 'general-product-charges',
                    'method' => 'general_product_charges_drop_down_list',
                ],
                [
                    'route' => 'member-dropdown-list',
                    'method' => 'member_drop_down_list',
                ],
                [
                    'route' => 'member-share-total-dropdown-list',
                    'method' => 'member_share_total_drop_down_list',
                ],
                [
                    'route' => 'member-dropdown-list-total-balance-accouts',
                    'method' => 'member_drop_down_list_total_balance_accounts',
                ],
                [
                    'route' => 'savings-products',
                    'method' => 'savings_products',
                ],
                [
                    'route' => 'get-product-charges',
                    'method' => 'get_product_charges',

                ],

            ]);
        });
        Route::group(['controller' => LoanProductController::class, 'middleware' => []], function () {
            routeListV2([
                [
                    'route' => 'loan-products',
                    'method' => 'dropdown_list',
                ],
            ]);
        });
    });

    Route::group(['controller' => LoanApplicationController::class, 'middleware' => []], function () {
        Route::group(['prefix' => 'loan-applications/'], function () {
            routeListV2([
                [
                    'route' => 'save-guarantors-none-member',
                    'method' => 'save_guarantors_none_member',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'save-guarantors',
                    'method' => 'save_guarantors',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'list',
                    'method' => 'get_loan_applications_list',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'transactions',
                    'method' => 'get_loan_applications_transactions',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'download-template',
                    'method' => 'loan_application_download_template',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'upload-loan-application-template',
                    'method' => 'loan_application_upload_template',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'loan-transactions-template',
                    'method' => 'loan_transactions_download_template',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'upload-loan-transaction-template',
                    'method' => 'loan_transactions_upload_template',
                    // 'permission' => 'save_guarantors',
                ],
                [
                    'route' => 'loan-repayment-template',
                    'method' => 'loan_repayment_download_template',
                    // 'permission' => 'save_guarantors',
                ],
            ]);
        });
    });
    Route::group(['controller' => SavingsAccountController::class, 'middleware' => []], function () {
        Route::group(['prefix' => 'savings-transfer/'], function () {
            routeListV2([
                [
                    'route' => 'list',
                    'method' => 'get_transfer_list',
                    'permission' => 'savings-transfer-list',
                ],
                [
                    'route' => 'details',
                    'method' => 'get_transfer_details',
                    'permission' => 'transfer-saving-details',
                ],
                [
                    'route' => 'create',
                    'method' => 'transfer_create',
                    'permission' => 'transfer-saving-create',
                ],
                [
                    'route' => 'delete',
                    'method' => 'transfer_delete',
                    'permission' => 'transfer-saving-delete',
                ],
            ]);
        });

        Route::group(['prefix' => 'group-account-savings/'], function () {

            Route::group(['prefix' => '/profile'], function () {
                routeListV2([

                    [
                        'route' => 'print',
                        'method' => 'print_group_account_profile',
                        // 'permission' => 'group-account-savings-create',
                    ],
                    [
                        'route' => 'group-members-with-running-loans',
                        'method' => 'get_group_member_with_running_loans',
                        // 'permission' => 'group-account-savings-create',
                    ],
                    [
                        'route' => 'group-transactions',
                        'method' => 'group_account_transactions',
                        // 'permission' => 'group-account-savings-create',
                    ],
                    [
                        'route' => 'download-export',
                        'method' => 'group_account_transactions_download_pdf',
                        // 'permission' => 'group-account-savings-create',
                    ],
                ]);
            });
            routeListV2([
                [
                    'route' => 'download-template',
                    'method' => 'group_account_download_template', // this is not the right method name
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'download-group-member-saving-template',
                    'method' => 'group_member_download_template', // this is not the right method name
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'import-group-account-member',
                    'method' => 'import_group_account_member', // this is not the right method name
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'import-groups',
                    'method' => 'import_groups', // this is not the right method name
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'add-member-group-dropdown-list',
                    'method' => 'add_member_group_drop_down_list',
                    // 'permission' => 'group-account-savings-create',
                ],

                [
                    'route' => 'group-saving-account-deposit-withdrawal',
                    'method' => 'group_saving_account_deposit_withdrawal',
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'create-group-saving-account',
                    'method' => 'create_group_saving_account',
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'collect-group-saving-account-list',
                    'method' => 'collect_group_saving_account_list',
                    // 'permission' => 'group-account-savings-create',
                ],
                [
                    'route' => 'profile-completeness',
                    'method' => 'group_account_profile_completeness',
                    // 'permission' => 'group_account_profile_completeness',
                ],
                // [
                //     'route' => 'download-template',
                //     'method' => 'group_account_profile_completeness_download_template',
                //     // 'permission' => 'group_account_profile_completeness',
                // ],
                [
                    'route' => 'download-group-members-list',
                    'method' => 'download_group_members_list',
                    // 'permission' => 'group_account_profile_completeness',
                ],
                [
                    'route' => 'savings-accounts-drop-down-list',
                    'method' => 'savings_accounts_drop_down_list',
                    // 'permission' => 'group-savings-accounts-dropdown',
                ],
                [
                    'route' => 'list',
                    'method' => 'get_group_account_list',
                    'permission' => 'group-savings-list',
                ],
                [
                    'route' => 'details',
                    'method' => 'get_group_account_details',
                    'permission' => 'group-saving-details',
                ],
                [
                    'route' => 'create',
                    'method' => 'group_account_create',
                    'permission' => 'group-saving-create',
                ],
                [
                    'route' => 'delete',
                    'method' => 'group_account_delete',
                    'permission' => 'group-saving-delete',
                ],
                [
                    'route' => 'groups-drop-down-list',
                    'method' => 'groups_drop_down_list',
                    // 'permission' => 'group-savings-accounts-dropdown',
                ],
            ]);
            Route::group(['prefix' => 'none-existing/'], function () {
                routeListV2([
                    [
                        'route' => 'list',
                        'method' => 'get_group_none_members_list',
                        // "permission" => "group-savings-list",
                    ],
                    [
                        'route' => 'details',
                        'method' => 'get_group_none_members_details',
                        // "permission" => "group-saving-details",
                    ],
                    [
                        'route' => 'create',
                        'method' => 'get_group_none_members_create',
                        // "permission" => "group-saving-create",
                    ],
                    [
                        'route' => 'delete',
                        'method' => 'get_group_none_members_delete',
                        // "permission" => "group-saving-delete",
                    ],
                ]);
            });
        });
        Route::group(['prefix' => 'members-account/'], function () {
            routeListV2([
                [
                    'route' => 'complete-transfer',
                    'method' => 'complete_member_account_transfer',
                    // 'permission' => 'list-members-account',
                ],
                [
                    'route' => 'reversal',
                    'method' => 'member_account_reversal',
                    // 'permission' => 'list-members-account',
                ],
                [
                    'route' => 'print',
                    'method' => 'print_member_account',
                    // 'permission' => 'list-members-account',
                ],
                [
                    'route' => 'list',
                    'method' => 'get_member_account_list',
                    'permission' => 'list-members-account',
                ],
                [
                    'route' => 'edit-details',
                    'method' => 'edit_member_account_details',
                    'permission' => 'edit-members-account-details',
                ],
                [
                    'route' => 'details',
                    'method' => 'get_member_account_details',
                    'permission' => 'get-members-account-details',
                ],
                [
                    'route' => 'create',
                    'method' => 'member_account_create',
                    'permission' => 'create-members-account',
                ],
                [
                    'route' => 'import-accounts',
                    'method' => 'import_member_accounts',
                    // 'permission' => 'import-members-account',
                ],
                [
                    'route' => 'delete',
                    'method' => 'member_account_delete',
                    'permission' => 'delete-members-account',
                ],
                [
                    'route' => 'withdrawal',
                    'method' => 'member_account_withdrawal',
                    // 'permission' => 'withdrawal-members-account',
                ],
                [

                    'route' => 'template',
                    'method' => 'download_members_account_import_template',
                    // 'permission' => 'withdrawal-members-account',
                ],
                [
                    'route' => 'deposit-template',
                    'method' => 'download_members_account_deposit_template',
                    // 'permission' => 'withdrawal-members-account',
                ],
                [
                    'route' => 'import-deposit-withdrawal',
                    'method' => 'import_members_withdrawal_and_deposits',
                    // 'permission' => 'withdrawal-members-account',
                ],
                [
                    'route' => 'import-opening-balance',
                    'method' => 'import_opening_balance',
                ],
            ]);
        });
    });
    Route::group(['prefix' => 'settings/'], function () {

        Route::group(['prefix' => 'general-charges/', 'controller' => GeneralChargeController::class], function () {
            routeListV2([

                [
                    'route' => 'list',
                    'method' => 'get_general_charge_list',
                ],
                [
                    'route' => 'create',
                    'method' => 'create_general_charge',
                    'permission' => 'general-charges-create',

                ],
                [
                    'route' => 'details',
                    'method' => 'get_general_charge_details',
                    'permission' => 'general-charges-details',

                ],
                [
                    'route' => 'delete',
                    'method' => 'get_general_charge_delete',
                    'permission' => 'general-charges-delete',

                ],

            ]);
        });
        Route::group(['controller' => TenantSettingsController::class, 'middleware' => []], function () {
            Route::group(['prefix' => 'branches/'], function () {
                routeListV2([
                    [
                        'route' => 'branches-dropdown-list',
                        'method' => 'branches_drop_down_list',
                    ],
                    [
                        'route' => 'list',
                        'method' => 'get_branch_list',
                    ],
                    [
                        'route' => 'create',
                        'method' => 'create_branch',
                        'permission' => 'branch-create',
                    ],
                    [
                        'route' => 'details',
                        'method' => 'get_branch_details',
                        'permission' => 'branch-details',

                    ],
                ]);
            });
            Route::group(['prefix' => 'system-audit-log/'], function () {
                routeListV2(
                    [
                        [
                        'route' => 'list',
                        'method' => 'get_system_audit_log_list',
                        // 'permission' => 'system-audit-log-list',
                    ],
                    ]
                );
            });
            // http://chesterfrank.localhost:3000/api/v1/tenant/settings/shares-dividends/shares/capitalize/list?status&branch_id=1

            Route::group(['prefix' => 'shares-dividends/'], function () {
                Route::group(['prefix' => 'share-transaction-charges/'], function () {
                    routeListV2([
                        [
                            'route' => 'create',
                            'method' => 'create_share_transaction_charge',
                            // 'permission' => 'create-capitalization',
                        ],
                        [
                            'route' => 'list',
                            'method' => 'get_share_transaction_charge_list',
                            // 'permission' => 'view-capitalization-list',
                        ],
                    ]);
                });
                Route::group(['prefix' => 'capitalize/'], function () {
                    routeListV2([
                        [
                            'route' => 'create',
                            'method' => 'create_capitalize',
                            'permission' => 'create-capitalization',
                        ],
                        [
                            'route' => 'list-log',
                            'method' => 'get_capitalize_log_list',
                            'permission' => 'view-capitalization-logs-list',

                        ],
                        [
                            'route' => 'list',
                            'method' => 'get_capitalize_list',
                            'permission' => 'view-capitalization-list',
                        ],
                    ]);
                });
                Route::group(['prefix' => 'shares/'], function () {
                    routeListV2([
                        [
                            'route' => 'list',
                            'method' => 'get_share_setting_list',
                        ],
                        [
                            'route' => 'list',
                            'method' => 'get_share_setting_list',
                        ],
                        [
                            'route' => 'save-changed-settings',
                            // "permission" => "save-changed-settings",
                            'method' => 'save_changed_settings_share',
                        ],

                    ]);
                });
            });
            Route::group(['prefix' => 'notifications/'], function () {
                routeListV2([
                    [
                        'route' => 'list',
                        'method' => 'get_notification_list',
                    ],

                ]);
                Route::group(['prefix' => 'sms-settings/'], function () {
                    routeListV2([
                        [
                            'route' => 'list',
                            'method' => 'get_notification_settings_list',
                        ],
                        [
                            'route' => 'sms-config-list',
                            'method' => 'get_sms_config_list',
                        ],
                        [
                            'route' => 'save-changed-settings',
                            // "permission" => "save-changed-settings",
                            'method' => 'save_changed_settings',
                        ],

                    ]);
                });
            });
            Route::group(['prefix' => 'savings-products/'], function () {
                Route::group(['prefix' => 'savings-accounts/'], function () {
                    routeListV2([
                        [
                            'route' => 'settings-list',
                            'method' => 'savings_settings_list',
                        ],
                        [
                            'route' => 'savings-group-settings-list',
                            'method' => 'savings_group_settings_list',
                        ],
                        [
                            'route' => 'save-changed-settings',
                            // "permission" => "save-changed-settings",
                            'method' => 'save_changed_settings',
                        ],
                    ]);
                });
            });
            Route::group(['prefix' => 'loan-settings/'], function () {
                routeListV2([
                    [
                        'route' => 'settings-list',
                        'method' => 'loan_settings_list',
                    ],
                    [
                        'route' => 'save-changed-settings',
                        // "permission" => "save-changed-settings",
                        'method' => 'save_changed_settings',
                    ],
                ]);
            });
            Route::group(['prefix' => 'member/'], function () {
                Route::group(['prefix' => 'onboarding/'], function () {
                    routeListV2([
                        [
                            'route' => 'settings-list',
                            'method' => 'onboarding_settings_list',
                        ],
                        [
                            'route' => 'save-changed-settings',
                            // "permission" => "save-changed-settings",
                            'method' => 'save_changed_settings',
                        ],
                    ]);
                });
            });

            Route::group(['prefix' => 'permisions/'], function () {
                routeList(['delete', 'remove_ability', 'add_ability'], 'permissions', 'tenant-settings-');
                routeListV2([
                    [
                        'route' => 'list',
                        'method' => 'get_permissions_list',
                        // "permission" => "permissions-list",
                    ],
                    [
                        'route' => 'details',
                        'method' => 'get_permissions_details',
                        // "permission" => "tenant-settings-permissions-holders-list",
                    ],
                    [
                        'route' => 'holders_list',
                        'method' => 'permissions_holders_list',
                        // "permission" => "tenant-settings-permissions-holders-list",
                    ],
                ]);
            });

            Route::group(['prefix' => 'roles/'], function () {
                routeList(['delete'], 'roles', 'tenant-settings-');
                routeListV2([
                    [
                        'route' => 'delete',
                        'method' => 'delete_role',
                        // "permission" => "settings-roles-add-ability",
                    ],
                    [
                        'route' => 'add_ability',
                        'method' => 'roles_add_ability',
                        // "permission" => "settings-roles-add-ability",
                    ],
                    [
                        'route' => 'remove_ability',
                        'method' => 'roles_remove_ability',
                        // "permission" => "settings-roles-remove-ability",

                    ],
                    [
                        'route' => 'holders_list',
                        'method' => 'roles_holders_list',
                        // "permission" => "settings-roles-holders-list",

                    ],
                    [
                        'route' => 'create',
                        'method' => 'roles_create',
                        // "permission" => "tenant-settings-roles-create",

                    ],
                    [
                        'route' => 'list',
                        'method' => 'get_roles_list',
                        // "permission" => "settings-roles-list",

                    ],
                    [
                        'route' => 'details',
                        'method' => 'get_roles_details',
                        // "permission" => "tenant-settings-roles-details",

                    ],
                    [
                        'route' => 'delete',
                        'method' => 'delete_role',
                        // "permission" => "settings-roles-delete",

                    ],
                ]);
                Route::post('permissions-drop-down', 'permissions_drop_down');
            });
        });
    });
    Route::group(['prefix' => 'staff/', 'controller' => TenantStaffController::class, 'middleware' => []], function () {
        routeList(['create', 'delete', 'details'], 'staff', '');
        routeListV2([
            [
                'route' => 'download-template',
                'method' => 'download_staff_import_template',
            ],
            [
                'route' => 'list',
                'method' => 'get_staff_list',
            ],
            [
                'route' => 'create',
                'method' => 'staff_create',
            ],
            [
                'route' => 'delete',
                'method' => 'delete_staff',
            ],
            [
                'route' => 'details',
                'method' => 'get_staff_details',
            ],
            [
                'route' => 'users-drop-down',
                'method' => 'users_drop_down',
            ],
            [
                'route' => 'roles-drop-down',
                'method' => 'roles_drop_down',
            ],
            [
                'route' => 'edit-details',
                'method' => 'edit_staff_details',
            ],

        ]);
    });

    Route::group(['prefix' => 'members/', 'controller' => MemberController::class, 'middleware' => []], function () {
        routeListV2([
            [
                'route' => 'list',
                'method' => 'get_members_list',
            ],
            [
                'route' => 'create',
                'method' => 'members_create',
            ],
            [
                'route' => 'activate',
                'method' => 'unarchive_members_action',///  make member domant
            ],
            [
                'route' => 'dormant',
                'method' => 'members_delete',///  make member domant
            ],
            [
                'route' => 'details',
                'method' => 'get_members_details',
            ],
            [
                'route' => 'download-template',
                'method' => 'download_member_template_keys',
            ],
            [
                'route' => 'import-data',
                'method' => 'import_member_data_excel',
            ],
            [
                'route' => 'profile-completeness',
                'method' => 'profile_completeness',
            ],
            [
                'route' => 'edit-details',
                'method' => 'edit_members_details',
            ],
            [
                'route' => 'charge-status',
                'method' => 'charge_member_status',
            ],
        ]);
        routeListV2([
            [
                'route' => 'print',
                'method' => 'print_member_list',
                // 'permission' => 'print-members-list',
            ],

        ]);

        Route::group(['prefix' => 'download-members-import-template/'], function () {
            routeListV2([
                [
                    'route' => 'list',
                    'method' => 'download_members_opening_balance_import_template',
                ],
                [
                    'route' => 'import-opening-balance',
                    'method' => 'import_opening_balance',
                ],

            ]);
        });

        // Route::post('settings-list',   "settings_list")->name('members-settings-list');
        // Route::post('users-drop-down',   "users_drop_down")->name('staff-users-drop-down');
    });
    Route::group(['prefix' => 'shares/', 'controller' => SharesController::class, 'middleware' => []], function () {
        Route::group(['prefix' => 'holders/'], function () {
            routeListV2([
                [
                    'route' => 'list',
                    'method' => 'get_shares_holder_list',
                ],
                [
                    'route' => 'details',
                    'method' => 'get_shares_holder_details',
                ],
                [
                    'route' => 'sell-shares',
                    'method' => 'sacco_selling_shares',
                ],
                [
                    'route' => 'share-withdrawal',
                    'method' => 'sacco_shares_withdrawal',
                ],
                [
                    'route' => 'transfer-shares',
                    'method' => 'sacco_shares_transfer',
                ],
                [
                    'route' => 'share-transaction-revert',
                    'method' => 'sacco_shares_transaction_revert',
                ],
                [
                    'route' => 'print-certificate',
                    'method' => 'print_share_certificate',
                ],
                [
                    'route' => 'print-full-certificate',
                    'method' => 'print_full_share_certificate',
                ],
            ]);
        });
        Route::group(['prefix' => 'transactions/'], function () {
            routeListV2([
                [
                    'route' => 'charge',
                    'method' => 'charge_share_transaction',
                ],
                [
                    'route' => 'list',
                    'method' => 'get_share_holder_transaction',
                ],
                [
                    'route' => 'details',
                    'method' => 'get_shares_holder_details',
                ],
            ]);
        });
    });
});
