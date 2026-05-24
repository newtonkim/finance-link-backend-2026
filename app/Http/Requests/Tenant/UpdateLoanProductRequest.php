<?php

namespace App\Http\Requests\Tenant;

use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanProductGuardService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UpdateLoanProductRequest extends StoreLoanProductRequest
{
    public function rules(): array
    {
        /** @var LoanProduct|null $product */
        $product = $this->route('loanProduct') ?? $this->route('loan_product');
        $rules = parent::rules();

        $rules['name'] = ['required', 'string', 'max:255', Rule::unique('tenant.loan_products', 'name')->ignore($product?->id)];
        $rules['code'] = ['nullable', 'string', 'max:100', Rule::unique('tenant.loan_products', 'code')->ignore($product?->id)];

        return $rules;
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var LoanProduct|null $product */
                $product = $this->route('loanProduct') ?? $this->route('loan_product');

                if (! $product instanceof LoanProduct) {
                    return;
                }

                try {
                    app(LoanProductGuardService::class)->ensureCoreFieldsAreEditable($product, $this->all());
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $field => $messages) {
                        foreach ($messages as $message) {
                            $validator->errors()->add($field, $message);
                        }
                    }
                }
            },
        ];
    }
}
