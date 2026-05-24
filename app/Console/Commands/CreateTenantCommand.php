<?php

namespace App\Console\Commands;

use App\Central\Models\Plan;
use App\Central\Services\TenantProvisioningService;
use Illuminate\Console\Command;

class CreateTenantCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:create {name} {subdomain}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new tenant with its own database and run migrations.';

    /**
     * Execute the console command.
     */
    public function handle(TenantProvisioningService $service)
    {
        $name = $this->argument('name');
        $subdomain = $this->argument('subdomain');
        $email = $this->ask('Admin email?', 'admin@'.$subdomain.'.com');
        $password = $this->secret('Admin password?');

        if (! $password) {
            $this->error('Password is required.');

            return 1;
        }

        $this->info("Creating tenant [{$name}] with subdomain [{$subdomain}]...");

        try {
            // Ensure a default plan exists for CLI creation
            $plan = Plan::first();
            if (! $plan) {
                $plan = Plan::create([
                    'name' => 'Basic',
                    'slug' => 'basic',
                    'price' => 0,
                    'days' => 365,
                ]);
            }

            $tenant = $service->createBaseAccount([
                'name' => $name,
                'subdomain' => $subdomain,
                'plan' => $plan->id,
                'license_months' => 'yearly',
            ]);

            $service->provisionResources($tenant, [
                'admin_email' => $email,
                'admin_password' => $password,
            ]);

            $this->info('Successfully created tenant!');
            $this->table(['ID', 'Name', 'Subdomain', 'Database'], [
                [$tenant->id, $tenant->name, $tenant->subdomain, $tenant->database_name],
            ]);
        } catch (\Exception $e) {
            $this->error('Failed to create tenant: '.$e->getMessage());
        }
    }
}
