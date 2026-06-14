<?php

namespace App\Domain\Licensing\Services;

use App\Central\Models\Plan;
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
        $planId = $this->planIdFor('monthly');

        return License::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'plan' => (string) ($planId ?? 'monthly'),
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
        $planId = $this->planIdFor('yearly');

        return License::create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'plan_id' => $planId,
            'plan' => (string) ($planId ?? 'yearly'),
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
            'plan_id' => $license->plan_id,
            'plan' => (string) ($license->plan_id ?? $license->plan),
            'starts_at' => now(),
            'expires_at' => $this->isMonthly($license)
                ? now()->addMonth()
                : now()->addYear(),
            'status' => 'active',
        ]);
    }

    private function planIdFor(string $slug): ?int
    {
        return Plan::query()->where('slug', $slug)->value('id');
    }

    private function isMonthly(License $license): bool
    {
        if ($license->plan_id) {
            return Plan::query()
                ->whereKey($license->plan_id)
                ->where('billing_cycle', 'monthly')
                ->exists();
        }

        return $license->plan === 'monthly';
    }
}
