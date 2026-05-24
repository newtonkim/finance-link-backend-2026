<?php

namespace App\Support;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * BranchQuery — applies branch filtering to Eloquent query builders for
 * actor-owned activity tables (e.g. Transaction, JournalEntry) that intentionally
 * do NOT use the BelongsToAuthenticatedBranch trait / BranchReadScope global scope.
 *
 * Usage:
 *   BranchQuery::apply(Transaction::query())
 *   BranchQuery::apply(Transaction::query(), 'transactions')  // explicit table alias
 */
class BranchQuery
{
    /**
     * Apply branch isolation to an Eloquent builder.
     *
     * - SCOPE_ALL admins: no filter applied (they see everything).
     * - SCOPE_BRANCH / SCOPE_SELF staff: filtered to their allowed branch IDs.
     * - No authenticated user (Artisan, seeders): no filter applied.
     *
     * @param  string|null  $tableAlias  Explicit table/alias to qualify the branch_id column.
     *                                   Defaults to the model's own table name.
     */
    public static function apply(Builder $query, ?string $tableAlias = null): Builder
    {
        $user = Auth::user();

        if (! $user instanceof Staff) {
            return $query;
        }

        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            return $query;
        }

        $allowedIds = BranchContext::allowedBranchIds();

        if (empty($allowedIds)) {
            return $query->whereRaw('1 = 0');
        }

        $table = $tableAlias ?? $query->getModel()->getTable();

        return $query->whereIn("{$table}.branch_id", $allowedIds);
    }

    /**
     * Apply an optional branch_id filter from the request (for list endpoints
     * where admins can optionally scope down to a single branch).
     *
     * Non-admin staff: always filtered to their own branch via apply().
     * Admin staff: filtered to the branch_id query param when provided.
     *
     * This is a convenience wrapper — it calls apply() first, then adds the
     * extra admin filter on top.
     */
    public static function applyWithFilter(Builder $query, ?string $tableAlias = null): Builder
    {
        $user = Auth::user();

        if (! $user instanceof Staff) {
            return $query;
        }

        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            $filterBranchId = BranchContext::filterBranchId();

            if ($filterBranchId !== null) {
                $table = $tableAlias ?? $query->getModel()->getTable();
                $query->where("{$table}.branch_id", $filterBranchId);
            }

            return $query;
        }

        return static::apply($query, $tableAlias);
    }
}
