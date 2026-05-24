<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Pagination\LengthAwarePaginator;

interface LoanProductServiceInterface
{
    /**
     * Return a paginated list of loan products, optionally filtered.
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Create a new loan product with its penalty rules.
     */
    public function create(array $data): LoanProduct;

    /**
     * Update an existing loan product and replace its penalty rules.
     */
    public function update(LoanProduct $product, array $data): bool;

    /**
     * Soft-delete a loan product.
     */
    public function delete(LoanProduct $product): ?bool;
}
