<?php

namespace App\Tenant\Modules\Accounting\Concerns;

use App\Support\BranchContext;
use App\Tenant\Modules\Settings\Models\CurrencySetting;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Branch scoping and currency checks shared by the ledger-based financial reports.
 */
trait ScopesLedgerReports
{
    /**
     * The branches the signed-in staff member may report on: every branch, or the
     * ones they are allowed, or the one they asked for if they may see it.
     *
     * @return array{all_branches: bool, branch_ids: list<int>, selected_branch_id: ?int}
     */
    private function scope(?int $branchId): array
    {
        $staff = BranchContext::getStaff();
        abort_unless($staff, 403, 'Staff access is required.');
        $all = BranchContext::scopeFor($staff) === BranchContext::SCOPE_ALL;
        $allowed = BranchContext::allowedBranchIds();
        if ($branchId !== null) {
            abort_unless(BranchContext::branchExists($branchId) && ($all || in_array($branchId, $allowed, true)), 403, 'You cannot access this branch.');

            return ['all_branches' => false, 'branch_ids' => [$branchId], 'selected_branch_id' => $branchId];
        }

        return ['all_branches' => $all, 'branch_ids' => $all ? [] : $allowed, 'selected_branch_id' => null];
    }

    /** Reports add UGX amounts together, so refuse to run over any other currency. */
    private function assertCurrency(Builder $query): void
    {
        $currency = CurrencySetting::query()->value('default_currency') ?? 'UGX';
        if ($currency !== 'UGX' || (clone $query)->where(function ($q) {
            $q->whereNull('je.currency_code')->orWhere('je.currency_code', '!=', 'UGX');
        })->exists()) {
            throw ValidationException::withMessages(['currency' => 'This report requires UGX ledger amounts. Foreign-currency conversion has not been established; correct the currency configuration or postings first.']);
        }
    }
}
