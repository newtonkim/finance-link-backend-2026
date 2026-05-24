<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Loans\Contracts\LoanPenaltyCalculatorServiceInterface;
use Illuminate\Console\Command;

class CheckLoanArrearsCommand extends Command
{
    protected $signature = 'loan:check-arrears
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Assess penalties and flag overdue loans as arrears across all tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSubdomain = $this->option('tenant');

        $query = Tenant::where('status', 'active');
        if ($tenantSubdomain) {
            $query->where('subdomain', $tenantSubdomain);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $totalAssessed = 0;
        $totalSkipped = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switch($tenant);

                /** @var LoanPenaltyCalculatorServiceInterface $calculator */
                $calculator = app(LoanPenaltyCalculatorServiceInterface::class);
                $result = $calculator->assessAll();

                $totalAssessed += $result['assessed'];
                $totalSkipped += $result['skipped'];

                $this->line("  [{$tenant->subdomain}] assessed={$result['assessed']} skipped={$result['skipped']}");
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. assessed={$totalAssessed} skipped={$totalSkipped}");

        return self::SUCCESS;
    }
}
