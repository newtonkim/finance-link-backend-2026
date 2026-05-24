<?php

namespace App\Support;

use App\Models\Staff;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Schema;

class BranchContext
{
    public const SCOPE_ALL = 'all';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_SELF = 'self';

    public static function getStaff(): ?Staff
    {
        $user = Auth::user();
        if ($user instanceof Staff) {
            return $user;
        }

        foreach (['sanctum', 'tenant'] as $guard) {
            try {
                $user = Auth::guard($guard)->user();
                if ($user instanceof Staff) {
                    return $user;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    public static function actingBranchId(): ?int
    {
        $user = self::getStaff();

        if (! $user instanceof Staff) {
            return null;
        }

        $assignedBranchId = $user->branch_id ? (int) $user->branch_id : null;

        if (self::scopeFor($user) !== self::SCOPE_ALL) {
            return $assignedBranchId;
        }

        $requestedBranchId = Request::header('X-Acting-Branch-Id');

        if ($requestedBranchId !== null && self::branchExists((int) $requestedBranchId)) {
            return (int) $requestedBranchId;
        }

        return $assignedBranchId;
    }

    public static function filterBranchId(): ?int
    {
        $user = self::getStaff();

        if (! $user instanceof Staff) {
            return null;
        }

        $assignedBranchId = $user->branch_id ? (int) $user->branch_id : null;

        if (self::scopeFor($user) !== self::SCOPE_ALL) {
            return $assignedBranchId;
        }

        $requestedBranchId = Request::input('branch_id');

        if ($requestedBranchId !== null && self::branchExists((int) $requestedBranchId)) {
            return (int) $requestedBranchId;
        }

        return $assignedBranchId;
    }

    public static function authContextFor(?Staff $user): ?array
    {
        if (! $user instanceof Staff) {
            return null;
        }

        $assignedBranch = self::branchRecord($user->branch_id);
        $scope = self::scopeFor($user);

        return [
            'assigned_branch' => $assignedBranch,
            'active_branch_id' => self::filterBranchId(),
            'can_access_multiple_branches' => $scope === self::SCOPE_ALL,
            'show_branch_filter' => $scope !== self::SCOPE_SELF,
            'scope' => $scope,
            'available_branches' => self::availableBranchesFor($user),
        ];
    }

    public static function scopeFor(?Staff $user): string
    {
        if (! $user instanceof Staff) {
            return self::SCOPE_SELF;
        }

        if ((bool) $user->is_tenant_admin) {
            return self::SCOPE_ALL; // fast path, no DB query
        }

        try {
            if (Schema::connection('tenant')->hasTable('roles')) {
                $scope = DB::connection('tenant')
                    ->table('roles')
                    ->where('name', self::normalizedRoleNameFor($user))
                    ->value('branch_scope');

                if ($scope !== null) {
                    return $scope;
                }
            }
        } catch (\Exception $e) {
            // Schema check failed — safe default
        }

        return self::SCOPE_SELF; // safe default
    }

    /**
     * Returns the list of branch IDs the currently authenticated staff may READ.
     */
    public static function allowedBranchIds(): array
    {
        $user = self::getStaff();

        if (! $user instanceof Staff) {
            return [];
        }

        $ids = [];

        if (! empty($user->branch_id)) {
            $ids[] = (int) $user->branch_id;
        }

        // 1. Merge additional branches from the JSON column if it exists on the model
        if (! empty($user->branch_can_be_accessed)) {
            $extraIds = is_array($user->branch_can_be_accessed)
                ? $user->branch_can_be_accessed
                : json_decode((string) $user->branch_can_be_accessed, true) ?? [];

            $ids = array_values(array_unique(array_merge($ids, array_map('intval', $extraIds))));
        }

        // 2. Fallback/Merge any additional branches from the legacy pivot table (if it exists).
        try {
            if (Schema::connection('tenant')->hasTable('staff_branch_access')) {
                $extraIds = DB::connection('tenant')
                    ->table('staff_branch_access')
                    ->where('staff_id', $user->id)
                    ->pluck('branch_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $ids = array_values(array_unique(array_merge($ids, $extraIds)));
            }
        } catch (\Exception) {
            // Schema check failed — return primary branch only
        }

        return $ids;
    }

    public static function canActAcrossBranches(?Staff $user): bool
    {
        return self::scopeFor($user) === self::SCOPE_ALL;
    }

    public static function roleNameFor(Staff $user): ?string
    {
        if (! empty($user->role)) {
            return $user->role;
        }

        if (empty($user->role_id) || ! Schema::connection('tenant')->hasTable('roles')) {
            return null;
        }

        return DB::connection('tenant')
            ->table('roles')
            ->where('id', $user->role_id)
            ->value('name');
    }

    public static function normalizedRoleNameFor(Staff $user): string
    {
        return strtolower(str_replace(' ', '_', trim((string) self::roleNameFor($user))));
    }

    public static function branchExists(?int $branchId): bool
    {
        if (empty($branchId) || ! Schema::connection('tenant')->hasTable('branches')) {
            return false;
        }

        return DB::connection('tenant')
            ->table('branches')
            ->where('id', $branchId)
            ->exists();
    }

    public static function branchRecord($branchId): ?array
    {
        if (empty($branchId) || ! Schema::connection('tenant')->hasTable('branches')) {
            return null;
        }

        $hasSystemType = Schema::connection('tenant')->hasColumn('branches', 'system_type');
        $columns = array_filter(['id', 'name', 'code', $hasSystemType ? 'system_type' : null, 'is_active']);

        $branch = DB::connection('tenant')
            ->table('branches')
            ->where('id', $branchId)
            ->first(array_values($columns));

        return $branch ? (array) $branch : null;
    }

    public static function availableBranchesFor(Staff $user): array
    {
        if (! Schema::connection('tenant')->hasTable('branches')) {
            return [];
        }

        $hasSystemType = Schema::connection('tenant')->hasColumn('branches', 'system_type');
        $columns = array_values(array_filter(['id', 'name', 'code', $hasSystemType ? 'system_type' : null, 'is_active']));

        $query = DB::connection('tenant')
            ->table('branches')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->select($columns);

        if (self::scopeFor($user) !== self::SCOPE_ALL) {
            if (empty($user->branch_id)) {
                return [];
            }

            $query->where('id', $user->branch_id);
        }

        return $query->get()->map(fn ($branch) => (array) $branch)->all();
    }
}
