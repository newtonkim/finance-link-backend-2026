<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
// use App\Services\PatchTenantColumns;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;



class TenantsMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:migrate
                            {--tenant= : Migrate a specific tenant by subdomain}
                            {--path= : Specific path for migrations}
                            {--fresh : Drop all tables and re-run all migrations}
                            {--seed : Run seeders after migrating}
                            {--job= : Run a job after migration}
                            {--force : Force the operation in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for all (or a specific) tenant databases.';

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

        $this->info('Starting migrations for '.$tenants->count().' tenant(s)...');

        $failed = 0;

        foreach ($tenants as $tenant) {
            $this->comment("Migrating tenant: [{$tenant->name}] (DB: {$tenant->database_name})...");

            try {
                // 1. Switch to this tenant's database
                $switcher->switch($tenant);
                // 2. Build migration args
                $migrateArgs = [
                    '--database' => 'tenant',
                    '--path' => $this->option('path') ?: 'database/migrations/tenant',
                    '--force' => true,
                ];

                // 3. Run fresh if requested
                if ($this->option('fresh')) {
                    Artisan::call('migrate:fresh', $migrateArgs);
                    $this->line(Artisan::output());
                } else {
                    Artisan::call('migrate', $migrateArgs);
                    $this->line(Artisan::output());
                    try {
                        $patchTenantColumns = new PatchTenantColumns();
                        $patchTenantColumns->duplicateTenantSchemaToLogs($tenant);
                    } catch (\Exception $e) {
                        // sacco_logs DB may not exist in all environments — skip silently
                    }

                }

                // 4. Run seeders if requested
                if ($this->option('seed')) {
                    Artisan::call('tenants:seed', [
                        '--tenant' => $tenant->subdomain,
                        '--force' => true,
                    ]);
                    $this->line(Artisan::output());
                }
                if ($this->option('job')) {

                    $jobClass = $this->option('job');

                    dispatch(new $jobClass($tenant->id));

                    $this->info("Job dispatched for tenant: {$tenant->name}");
                }

                $this->info("✓ Completed migration for: {$tenant->name}");

                // 5. Clear the connection to avoid cross-pollution
                $switcher->purge();
            } catch (\Exception $e) {
                $this->error("✗ Failed to migrate [{$tenant->name}]: ".$e->getMessage());
                $failed++;
                $switcher->purge();
            }
        }

        $this->newLine();
        if ($failed > 0) {
            $this->warn("Migration finished with {$failed} failure(s).");

            return 1;
        }

        $this->info('All tenant migrations completed successfully.');

        return 0;
    }
}
