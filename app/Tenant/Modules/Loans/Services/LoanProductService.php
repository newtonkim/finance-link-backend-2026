<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\LoanProductServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LoanProductService implements LoanProductServiceInterface
{
    /**
     * Return a paginated, filtered list of loan products with their penalty rules.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = LoanProduct::query()->orderBy('created_at', 'desc');

        if (! empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        if ($this->supportsChargeAssignments()) {
            $query->with(['charges:'.$this->chargeSelectColumns()]);
        }

        return $query
            ->with('penaltyRules')
            ->withCount('loans')
            ->paginate($perPage);
    }

    /**
     * Create a loan product and persist any nested penalty rules.
     */
    public function create(array $data): LoanProduct
    {
        $penaltyRules = $data['penalty_rules'] ?? [];
        $chargeIds = $data['charge_ids'] ?? [];
        unset($data['penalty_rules'], $data['charge_ids']);

        $data['penalty_rate'] = $data['penalty_rate'] ?? 0;
        $data['code'] = $data['code'] ?? $this->generateCode((string) ($data['name'] ?? 'loan-product'));

        $product = LoanProduct::create($data);

        if (! empty($penaltyRules)) {
            $penaltyRules = array_map(fn ($rule) => array_merge($rule, ['amount' => $rule['amount'] ?? 0]), $penaltyRules);
            $product->penaltyRules()->createMany($penaltyRules);
        }

        if ($this->supportsChargeAssignments()) {
            $product->charges()->sync($chargeIds);
        }

        return $product;
    }

    /**
     * Update a loan product and replace its penalty rules.
     */
    public function update(LoanProduct $product, array $data): bool
    {
        $penaltyRules = $data['penalty_rules'] ?? [];
        $chargeIds = $data['charge_ids'] ?? [];
        unset($data['penalty_rules'], $data['charge_ids']);

        $data['penalty_rate'] = $data['penalty_rate'] ?? 0;

        $updated = $product->update($data);

        if ($updated) {
            $product->penaltyRules()->delete();

            if (! empty($penaltyRules)) {
                $penaltyRules = array_map(fn ($rule) => array_merge($rule, ['amount' => $rule['amount'] ?? 0]), $penaltyRules);
                $product->penaltyRules()->createMany($penaltyRules);
            }

            if ($this->supportsChargeAssignments()) {
                $product->charges()->sync($chargeIds);
            }
        }

        return $updated;
    }

    /**
     * Soft-delete a loan product.
     */
    public function delete(LoanProduct $product): ?bool
    {
        return $product->delete();
    }

    private function generateCode(string $name): string
    {
        $base = Str::upper(Str::slug($name !== '' ? $name : 'loan-product', '-'));

        return Str::limit($base !== '' ? $base : 'LOAN-PRODUCT', 24, '');
    }

    private function supportsChargeAssignments(): bool
    {
        return Schema::connection('tenant')->hasTable('loan_product_charge')
            && Schema::connection('tenant')->hasTable('loan_charges');
    }

    private function chargeSelectColumns(): string
    {
        $columns = ['id', 'name'];

        foreach (['category', 'charge_type', 'value', 'frequency', 'grace_days'] as $column) {
            if (Schema::connection('tenant')->hasColumn('loan_charges', $column)) {
                $columns[] = $column;
            }
        }

        return implode(',', $columns);
    }
}
