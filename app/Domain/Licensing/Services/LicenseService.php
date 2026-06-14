<?php

namespace App\Domain\Licensing\Services;

use App\Central\Models\Plan;
use App\Domain\Licensing\Entities\License;

class LicenseService
{
    /**
     * Create a new license.
     */
    public function createLicense(array $data)
    {
        $data = $this->normalizePlan($data);

        return License::create($data);
    }

    /**
     * Update an existing license.
     */
    public function updateLicense(License $license, array $data): bool
    {
        $data = $this->normalizePlan($data);

        return $license->update($data);
    }

    /**
     * Delete a license.
     */
    public function deleteLicense(License $license): ?bool
    {
        return $license->delete();
    }

    private function normalizePlan(array $data): array
    {
        $identifier = $data['plan_id'] ?? $data['plan'] ?? null;

        if (! $identifier) {
            return $data;
        }

        $planId = Plan::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->value('id');

        if ($planId) {
            $data['plan_id'] = $planId;
            $data['plan'] = (string) $planId;
        }

        return $data;
    }
}
