<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class SavingsProductService
{
    /**
     * List savings products.
     */
    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = SavingsProduct::query()->orderBy('created_at', 'desc');

        if (! empty($filters['search'])) {
            $query->where('name', 'like', "%{$filters['search']}%");
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->with('charges')->paginate($perPage);
    }

    private const FD_FIELDS = [
        'interest_rate',
        'interest_payout_type',
        'interest_posting_frequency',
        'default_tenor_months',
        'maturity_action',
        'convert_to_product_id',
        'interest_expense_account_id',
        'interest_payable_account_id',
    ];

    /**
     * Create a new savings product.
     */
    public function create(array $data): SavingsProduct
    {
        $charges = $data['charges'] ?? [];
        unset($data['charges']);

        if (($data['type'] ?? '') !== 'fixed') {
            $data = array_diff_key($data, array_flip(self::FD_FIELDS));
        }

        $data['code'] = $data['code'] ?? $this->generateCode((string) ($data['name'] ?? 'savings-product'));
        $requestBranchId = filter_var(request('branch_id'), FILTER_VALIDATE_INT);
        $data['branch_id'] = $requestBranchId !== false ? $requestBranchId : auth()->user()?->branch_id;
        $product = SavingsProduct::create($data);

        if (! empty($charges)) {
            $product->charges()->createMany($charges);
        }

        return $product;
    }

    /**
     * Update an existing savings product.
     */
    public function update(SavingsProduct $product, array $data): bool
    {
        $charges = $data['charges'] ?? [];
        unset($data['charges']);

        if (($data['type'] ?? $product->type) !== 'fixed') {
            $data = array_diff_key($data, array_flip(self::FD_FIELDS));
        }

        $updated = $product->update($data);

        if ($updated) {
            $product->charges()->delete();
            if (! empty($charges)) {
                $product->charges()->createMany($charges);
            }
        }

        return $updated;
    }

    /**
     * Delete a savings product.
     */
    public function delete(SavingsProduct $product): ?bool
    {
        return $product->delete();
    }

    private function generateCode(string $name): string
    {
        $base = Str::upper(Str::slug($name !== '' ? $name : 'savings-product', '-'));

        return Str::limit($base !== '' ? $base : 'SAVINGS-PRODUCT', 24, '');
    }
}
