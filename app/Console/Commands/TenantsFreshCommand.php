<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Database\Seeders\TenantSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class TenantsFreshCommand extends Command
{
    protected $signature = 'tenants:fresh
                            {--subdomain= : Subdomain of the tenant to wipe and re-migrate (required)}
                            {--seed : Re-run TenantSeeder after migrating}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Drop all tables in a single tenant database and re-run all tenant migrations. Destructive.';

    public function handle(DatabaseSwitcher $switcher): int
    {
        $subdomain = $this->option('subdomain');

        if (! $subdomain) {
            $this->error('--subdomain is required. Refusing to wipe every tenant at once.');

            return self::FAILURE;
        }

        $tenant = Tenant::on('master')->where('subdomain', $subdomain)->first();
        if (! $tenant) {
            $this->error("No tenant found with subdomain [{$subdomain}].");

            return self::FAILURE;
        }

        $this->warn("This will DROP every table in [{$tenant->database_name}] and lose all data for tenant [{$tenant->name}].");

        if (! $this->option('force') && ! $this->confirm("Type 'yes' to proceed with migrate:fresh on [{$tenant->database_name}]")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        try {
            $switcher->switch($tenant);

            $this->info("Running migrate:fresh on [{$tenant->database_name}]...");
            Artisan::call('migrate:fresh', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ], $this->getOutput());

            if ($this->option('seed')) {
                $this->info('Re-seeding tenant...');
                Artisan::call('db:seed', [
                    '--database' => 'tenant',
                    '--class' => TenantSeeder::class,
                    '--force' => true,
                ], $this->getOutput());
            }

            $this->info("✓ Done. Tenant [{$tenant->name}] has been reset.");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Failed to refresh tenant [{$tenant->name}]: ".$e->getMessage());

            return self::FAILURE;
        } finally {
            $switcher->purge();
        }
    }
}
