<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenameOverdueToArrearsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'loan:rename-overdue-to-arrears
                            {--tenant= : Run for a specific tenant subdomain only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update existing "overdue" schedule statuses to "arrears" across all tenants.';

    /**
     * Create a new command instance.
     */
    public function __construct(protected DatabaseSwitcher $switcher)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
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

        $totalUpdated = 0;

        foreach ($tenants as $tenant) {
            try {
                $this->switcher->switch($tenant);

                $updated = DB::connection('tenant')
                    ->table('loan_repayment_schedule')
                    ->where('status', 'overdue')
                    ->update(['status' => 'arrears']);

                $totalUpdated += $updated;

                $this->info("  [{$tenant->subdomain}] updated {$updated} records.");
            } catch (\Throwable $e) {
                $this->error("  [{$tenant->subdomain}] failed: {$e->getMessage()}");
            }
        }

        $this->info("Done. Total records updated: {$totalUpdated}");

        return self::SUCCESS;
    }
}
