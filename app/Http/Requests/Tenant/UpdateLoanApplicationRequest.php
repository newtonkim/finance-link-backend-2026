<?php

namespace App\Http\Requests\Tenant;

use App\Tenant\Modules\Loans\Models\LoanApplication;

class UpdateLoanApplicationRequest extends StoreLoanApplicationRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // On update, member and product are not required
        $rules['member_id'] = ['nullable', 'integer', 'exists:tenant.members,id'];
        $rules['loan_product_id'] = ['nullable', 'integer', 'exists:tenant.loan_products,id'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function ($validator): void {
                /** @var LoanApplication|null $application */
                $application = $this->route('loanApplication');

                if (! $application instanceof LoanApplication) {
                    return;
                }

                $editableStatuses = [
                    LoanApplication::STATUS_DRAFT,
                    LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
                ];

                if (! in_array($application->status, $editableStatuses, true)) {
                    $validator->errors()->add(
                        'status',
                        'Only draft or returned-for-correction applications can be updated.'
                    );
                }
            },
        ];
    }
}
