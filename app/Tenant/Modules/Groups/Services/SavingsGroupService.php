<?php

namespace App\Tenant\Modules\Groups\Services;

use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SavingsGroupService
{
    /**
     * Create a new savings group.
     */
    public function create(array $data): SavingsGroup
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($data) {
                if (isset($data['image'])) {
                    $data['image_path'] = $this->handleImageUpload($data['image']);
                }

                $data['created_by'] = auth()->id();
                $data['code'] = $data['code'] ?? 'GRP-' . strtoupper(substr(uniqid(), -6));

                return SavingsGroup::create($data);
            });
        } catch (\Exception $e) {
            Log::error('Savings Group Creation Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Update an existing savings group.
     */
    public function update(SavingsGroup $group, array $data): SavingsGroup
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($group, $data) {
                if (isset($data['image'])) {
                    // Delete old image if exists
                    if ($group->image_path) {
                        Storage::disk('public')->delete($group->image_path);
                    }
                    $data['image_path'] = $this->handleImageUpload($data['image']);
                }

                $group->update($data);

                return $group;
            });
        } catch (\Exception $e) {
            Log::error('Savings Group Update Failed: '.$e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle image upload and return the path.
     */
    private function handleImageUpload($image): string
    {
        return $image->store('savings_groups', 'public');
    }

    /**
     * Delete a savings group.
     */
    public function delete(SavingsGroup $group): bool
    {
        try {
            return DB::connection('tenant')->transaction(function () use ($group) {
                if ($group->image_path) {
                    Storage::disk('public')->delete($group->image_path);
                }

                return $group->delete();
            });
        } catch (\Exception $e) {
            Log::error('Savings Group Deletion Failed: '.$e->getMessage());
            throw $e;
        }
    }
}
