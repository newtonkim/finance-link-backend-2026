<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Validation\ValidationException;

class LoanProductGuardService
{
    private const LOCKED_FIELDS = [
        'interest_method',
        'repayment_structure',
        'interest_period',
        'loan_duration',
        'duration_type',
        'repayment_cycle',
    ];

    public function isInUse(LoanProduct $product): bool
    {
        if ($product->loans_count !== null) {
            return $product->loans_count > 0;
        }

        return $product->loans()->exists();
    }

    public function canEditCoreFields(LoanProduct $product): bool
    {
        return ! $this->isInUse($product);
    }

    /**
     * @throws ValidationException
     */
    public function ensureCoreFieldsAreEditable(LoanProduct $product, array $data): void
    {
        if (! $this->isInUse($product)) {
            return;
        }

        $errors = [];

        foreach (self::LOCKED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            if ((string) $product->{$field} !== (string) $data[$field]) {
                $errors[$field] = ['This field cannot be changed once the product has active or historical loans.'];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
