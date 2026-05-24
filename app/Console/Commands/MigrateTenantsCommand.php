<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class MigrateTenantsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:migrate-all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for all registered tenants.';

    /**
     * Execute the console command.
     */
    public function handle(DatabaseSwitcher $switcher)
    {
        $tenants = Tenant::where('status', 'active')->get();

        $this->info('Starting migrations for '.$tenants->count().' active tenants...');

        foreach ($tenants as $tenant) {
            $this->comment("Migrating tenant: [{$tenant->name}] (DB: {$tenant->database_name})...");

            try {
                // 1. Switch to this tenant's database
                $switcher->switch($tenant);

                // 2. Run the migrations for the 'tenant' directory
                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations/tenant',
                    '--force' => true,
                ]);

                $this->info('Completed migration for: '.$tenant->name);

                // 3. Clear the connection to avoid cross-pollution
                $switcher->purge();

            } catch (\Exception $e) {
                $this->error("Failed to migrate [{$tenant->name}]: ".$e->getMessage());
            }
        }

        $this->info('Multi-tenant migration process finished.');
    }
}
