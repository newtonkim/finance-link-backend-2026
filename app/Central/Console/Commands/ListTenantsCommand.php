<?php

namespace App\Central\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Console\Command;

class ListTenantsCommand extends Command
{
    protected $signature = 'central:tenant:list';

    protected $description = 'List all tenants and their current status';

    public function handle(): void
    {
        $tenants = Tenant::with('activeLicense.plan')->get();

        $rows = $tenants->map(fn ($t) => [
            $t->id,
            $t->name,
            $t->subdomain,
            $t->status,
            $t->activeLicense?->plan?->name ?? 'No Plan',
            $t->activeLicense?->expires_at?->toDateTimeString() ?? 'N/A',
        ]);

        $this->table(['ID', 'Name', 'Subdomain', 'Status', 'Plan', 'Expires'], $rows);
    }
}
