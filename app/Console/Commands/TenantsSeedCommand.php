<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenantsSeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:seed
                            {--tenant= : Seed a specific tenant by subdomain}
                            {--class=Database\\Seeders\\TenantSeeder : The seeder class to run}
                            {--force : Force the operation in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run seeders for all (or a specific) tenant databases.';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseSwitcher $switcher): int
    {
        $tenantOption = $this->option('tenant');

        if ($tenantOption) {
            $tenants = Tenant::where('subdomain', $tenantOption)->get();
            if ($tenants->isEmpty()) {
                $this->error("Tenant with subdomain [{$tenantOption}] not found.");

                return 1;
            }
        } else {
            $tenants = Tenant::where('status', '!=', 'deleted')->get();
        }

        $seederClass = $this->option('class');

        $this->info('Starting seeding for '.$tenants->count()." tenant(s) using [{$seederClass}]...");

        $failed = 0;

        foreach ($tenants as $tenant) {
            $this->comment("Seeding tenant: [{$tenant->name}] (DB: {$tenant->database_name})...");

            try {
                // 1. Switch to this tenant's database
                $switcher->switch($tenant);

                // 2. Run the seeder
                Artisan::call('db:seed', [
                    '--database' => 'tenant',
                    '--class' => $seederClass,
                    '--force' => $this->option('force'),
                ]);

                $this->line(Artisan::output());
                $this->info("✓ Completed seeding for: {$tenant->name}");

                // 3. Clear the connection to avoid cross-pollution
                $switcher->purge();

            } catch (\Exception $e) {
                $this->error("✗ Failed to seed [{$tenant->name}]: ".$e->getMessage());
                $failed++;
                $switcher->purge();
            }
        }

        $this->newLine();
        if ($failed > 0) {
            $this->warn("Seeding finished with {$failed} failure(s).");

            return 1;
        }

        $this->info('All tenant seeding completed successfully.');

        return 0;
    }
}
