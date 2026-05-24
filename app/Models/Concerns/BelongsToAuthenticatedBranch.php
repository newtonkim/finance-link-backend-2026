<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchReadScope;
use App\Support\BranchContext;

trait BelongsToAuthenticatedBranch
{
    protected static function bootBelongsToAuthenticatedBranch(): void
    {
        // Enforce branch read scope on every SELECT for branch-owned master tables.
        // Do NOT add this trait to actor-owned activity tables (e.g. Transaction).
        static::addGlobalScope(new BranchReadScope);

        // Auto-stamp branch_id on INSERT via actingBranchId() — never from request body.
        static::creating(function ($model) {
            if (! empty($model->branch_id)) {
                return;
            }

            $branchId = BranchContext::actingBranchId();

            if ($branchId !== null) {
                $model->branch_id = $branchId;
            }
        });
    }
}
