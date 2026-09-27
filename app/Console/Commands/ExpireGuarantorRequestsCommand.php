<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use Illuminate\Console\Command;

/**
 * Expires guarantor consent requests past their deadline, which frees the capacity
 * they were holding. Screens that read guarantors also expire overdue requests as
 * they load, so this only keeps guarantors' capacity accurate between visits.
 */
class ExpireGuarantorRequestsCommand extends Command
{
    protected $signature = 'loan:expire-guarantor-requests
                            {--tenant= : Run for a specific tenant subdomain only}';

    protected $description = 'Expire guarantor consent requests that were not answered in time, across all tenants.';

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

                $expired = app(LoanGuarantorServiceInterface::class)->expireOverdue();
                $total += $expired;

                $this->line("  [{$tenant->subdomain}] expired={$expired}");
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. expired={$total}");

        return self::SUCCESS;
    }
}
