<?php

namespace App\Central\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Console\Command;

class SuspendTenantCommand extends Command
{
    protected $signature = 'central:tenant:suspend {subdomain}';

    protected $description = 'Suspend a tenant by their subdomain';

    public function handle(): int
    {
        $subdomain = $this->argument('subdomain');
        $tenant = Tenant::where('subdomain', $subdomain)->first();

        if (! $tenant) {
            $this->error("Tenant with subdomain [{$subdomain}] not found.");

            return 1;
        }

        $tenant->update(['status' => 'suspended']);
        $this->info("Tenant [{$tenant->name}] has been suspended.");

        return 0;
    }
}
