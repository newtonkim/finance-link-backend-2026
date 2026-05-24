<?php

namespace App\Providers;

use App\Models\Member;
use App\Tenant\Modules\Accounting\Contracts\SavingsCoaResolverInterface;
use App\Tenant\Modules\Accounting\Repositories\ChartOfAccountRepository;
use App\Tenant\Modules\Accounting\Repositories\ChartOfAccountRepositoryInterface;
use App\Tenant\Modules\Accounting\Services\SavingsCoaResolver;
use App\Tenant\Modules\Loans\Contracts\LoanActivityServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanAgingReportServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanApplicationServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanAppraisalServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanApprovalServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanDisbursementServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanDocumentServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanEligibilityServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanPenaltyCalculatorServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanRepaymentServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanTimelineServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use App\Tenant\Modules\Loans\Contracts\MemberLoanSummaryServiceInterface;
use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanTransaction;
use App\Tenant\Modules\Loans\Services\LoanActivityService;
use App\Tenant\Modules\Loans\Services\LoanAgingReportService;
use App\Tenant\Modules\Loans\Services\LoanApplicationService;
use App\Tenant\Modules\Loans\Services\LoanAppraisalService;
use App\Tenant\Modules\Loans\Services\LoanApprovalService;
use App\Tenant\Modules\Loans\Services\LoanDisbursementService;
use App\Tenant\Modules\Loans\Services\LoanDocumentService;
use App\Tenant\Modules\Loans\Services\LoanEligibilityService;
use App\Tenant\Modules\Loans\Services\LoanPenaltyCalculatorService;
use App\Tenant\Modules\Loans\Services\LoanProductService;
use App\Tenant\Modules\Loans\Services\LoanRepaymentService;
use App\Tenant\Modules\Loans\Services\LoanTimelineService;
use App\Tenant\Modules\Loans\Services\LoanWriteOffService;
use App\Tenant\Modules\Loans\Services\MemberLoanSummaryService;
use App\Tenant\Modules\Loans\Services\ScheduleGeneratorService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Shares\Contracts\ShareAccountingServiceInterface;
use App\Tenant\Modules\Shares\Models\Share;
use App\Tenant\Modules\Shares\Services\ShareAccountingService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            ChartOfAccountRepositoryInterface::class,
            ChartOfAccountRepository::class
        );

        $this->app->bind(
            LoanProductServiceInterface::class,
            LoanProductService::class
        );

        $this->app->bind(
            LoanApplicationServiceInterface::class,
            LoanApplicationService::class
        );

        $this->app->bind(
            LoanEligibilityServiceInterface::class,
            LoanEligibilityService::class
        );

        $this->app->bind(
            MemberLoanSummaryServiceInterface::class,
            MemberLoanSummaryService::class
        );

        $this->app->bind(
            LoanDocumentServiceInterface::class,
            LoanDocumentService::class
        );

        $this->app->bind(
            LoanAppraisalServiceInterface::class,
            LoanAppraisalService::class
        );

        $this->app->bind(
            LoanApprovalServiceInterface::class,
            LoanApprovalService::class
        );

        $this->app->bind(
            LoanTimelineServiceInterface::class,
            LoanTimelineService::class
        );

        $this->app->bind(
            LoanActivityServiceInterface::class,
            LoanActivityService::class
        );

        $this->app->bind(
            LoanDisbursementServiceInterface::class,
            LoanDisbursementService::class
        );

        $this->app->bind(
            ScheduleGeneratorServiceInterface::class,
            ScheduleGeneratorService::class
        );

        $this->app->bind(
            LoanRepaymentServiceInterface::class,
            LoanRepaymentService::class
        );

        $this->app->bind(
            LoanPenaltyCalculatorServiceInterface::class,
            LoanPenaltyCalculatorService::class
        );

        $this->app->bind(
            LoanAgingReportServiceInterface::class,
            LoanAgingReportService::class
        );

        $this->app->bind(
            ShareAccountingServiceInterface::class,
            ShareAccountingService::class
        );

        $this->app->bind(
            LoanWriteOffServiceInterface::class,
            LoanWriteOffService::class
        );

        $this->app->bind(
            SavingsCoaResolverInterface::class,
            SavingsCoaResolver::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Expenses\Contracts\ExpenseThresholdServiceInterface::class,
            \App\Tenant\Modules\Expenses\Services\ExpenseThresholdService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Expenses\Contracts\ExpenseBudgetServiceInterface::class,
            \App\Tenant\Modules\Expenses\Services\ExpenseBudgetService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface::class,
            \App\Tenant\Modules\Charges\Services\ChargeJournalService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface::class,
            \App\Tenant\Modules\Charges\Services\ChargeCalculatorService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface::class,
            \App\Tenant\Modules\Charges\Services\ChargeApplicationService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Savings\Contracts\FdMaturityAccountingServiceInterface::class,
            \App\Tenant\Modules\Savings\Services\FdMaturityAccountingService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface::class,
            \App\Tenant\Modules\Accounting\Services\TrialBalanceService::class
        );

        $this->app->bind(
            \App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface::class,
            \App\Tenant\Modules\Savings\Services\SavingsAccountStatementService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'deposit' => Transaction::class,
            'deposits' => SavingsAccount::class,
            'withdrawal' => Transaction::class,
            'widrawal' => Transaction::class,
            'withdraw' => Transaction::class,
            'deposit-charge' => Transaction::class,
            'share-transaction-selling-charge' => Transaction::class,
            'share-transaction' => Transaction::class,
            'withdrawal-charge' => Transaction::class,
            'loan' => Loan::class,
            'loan_transaction' => LoanTransaction::class,
            'general-charge' => LoanTransaction::class,
            'savings_account' => SavingsAccount::class,
            'member' => Member::class,
            'shares' => Share::class,
        ]);

        $this->loadMigrationsFrom([
            database_path('migrations/landlord'),
        ]);

        $this->registerBlueprintMacros();
        $this->configureDefaults();
        $this->configureTenantRoutes();
    }

    /**
     * Register reusable Blueprint macros for all migrations.
     */
    protected function registerBlueprintMacros(): void
    {
        Blueprint::macro('auditColumns', function () {
            /** @var Blueprint $this */
            $this->unsignedBigInteger('created_by')->nullable();
            $this->unsignedBigInteger('updated_by')->nullable();
            $this->unsignedBigInteger('deleted_by')->nullable();
            $this->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created');
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );
    }

    /**
     * Configure tenant-specific routes.
     * Routes are registered in routes/api.php — avoid duplicate registration here.
     */
    protected function configureTenantRoutes(): void {}
}
