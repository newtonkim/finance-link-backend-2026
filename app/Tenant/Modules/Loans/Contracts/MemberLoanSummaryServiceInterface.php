<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Models\Member;

interface MemberLoanSummaryServiceInterface
{
    public function summarize(Member $member): array;
}
