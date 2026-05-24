<?php

namespace App\Central\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExtendLicenseCommand extends Command
{
    protected $signature = 'central:license:extend {subdomain} {days=30}';

    protected $description = 'Extend an active license for a tenant';

    public function handle(): int
    {
        $subdomain = $this->argument('subdomain');
        $days = (int) $this->argument('days');

        $tenant = Tenant::with('activeLicense')->where('subdomain', $subdomain)->first();

        if (! $tenant || ! $tenant->activeLicense) {
            $this->error("Tenant with subdomain [{$subdomain}] or active license not found.");

            return 1;
        }

        $license = $tenant->activeLicense;
        $license->update([
            'expires_at' => Carbon::parse($license->expires_at)->addDays($days),
        ]);

        $this->info("License for [{$tenant->name}] extended by {$days} days. New expiry: {$license->expires_at}");

        return 0;
    }
}
