<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Loans\Services\GuarantorArrearsService;
use Illuminate\Console\Command;

/**
 * Sends the arrears warning to guarantors whose loans have been overdue long enough,
 * across all tenants. Each tenant's own settings decide when, and how often.
 */
class NotifyGuarantorsOfArrearsCommand extends Command
{
    protected $signature = 'loan:notify-guarantors-arrears
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Warn guarantors when the loan they guarantee is overdue, across all tenants.';

    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = Tenant::where('status', 'active');
        if ($subdomain = $this->option('tenant')) {
            $query->where('subdomain', $subdomain);
        }

        $total = 0;

        foreach ($query->get() as $tenant) {
            try {
                $this->switcher->switch($tenant);

                $notified = app(GuarantorArrearsService::class)->notifyDue($tenant->subdomain);
                $total += $notified;

                $this->line("  [{$tenant->subdomain}] notified={$notified}");
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. notified={$total}");

        return self::SUCCESS;
    }
}
