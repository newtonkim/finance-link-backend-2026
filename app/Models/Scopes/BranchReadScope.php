<?php

namespace App\Models\Scopes;

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class BranchReadScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // No authenticated user (Artisan, seeders, commands): no filter
        if (! $user instanceof Staff) {
            return;
        }

        // SCOPE_ALL admins: see everything
        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            return;
        }

        $allowedIds = BranchContext::allowedBranchIds();

        if (empty($allowedIds)) {
            // Staff with no branch assigned: see nothing rather than everything
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->whereIn($model->getTable().'.branch_id', $allowedIds);
    }
}
