<?php

namespace App\Domain\Licensing\Services;

use App\Domain\Licensing\Entities\License;

class LicenseService
{
    /**
     * Create a new license.
     */
    public function createLicense(array $data)
    {
        return License::create($data);
    }

    /**
     * Update an existing license.
     */
    public function updateLicense(License $license, array $data): bool
    {
        return $license->update($data);
    }

    /**
     * Delete a license.
     */
    public function deleteLicense(License $license): ?bool
    {
        return $license->delete();
    }
}
