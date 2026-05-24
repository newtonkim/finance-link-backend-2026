<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Licensing\Entities\License;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Support\Str;

class LicenseGenerator
{
    /**
     * Generate a monthly license for the given tenant.
     */
    public function generateMonthly(Tenant $tenant): License
    {
        return License::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan' => 'monthly',
            'starts_at' => now(),
            'expires_at' => now()->addMonth(),
            'status' => 'active',
        ]);
    }

    /**
     * Generate a yearly license for the given tenant.
     */
    public function generateYearly(Tenant $tenant): License
    {
        return License::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan' => 'yearly',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ]);
    }

    /**
     * Renew an existing license by expiring the old one and creating a new record.
     */
    public function renew(License $license): License
    {
        // 1. Mark existing license as expired
        $license->update(['status' => 'expired']);

        // 2. Create a fresh license record (Audit Trail preserved)
        return License::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $license->tenant_id,
            'plan' => $license->plan,
            'starts_at' => now(),
            'expires_at' => $license->plan === 'monthly'
                ? now()->addMonth()
                : now()->addYear(),
            'status' => 'active',
        ]);
    }
}
