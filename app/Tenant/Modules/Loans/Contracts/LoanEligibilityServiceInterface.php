<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Models\Member;
use App\Tenant\Modules\Loans\Data\EligibilityResult;
use App\Tenant\Modules\Loans\Models\LoanProduct;

interface LoanEligibilityServiceInterface
{
    public function evaluate(
        Member $member,
        LoanProduct $product,
        float $amount,
        int $term,
    ): EligibilityResult;
}
