<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\MemberLoanSummaryServiceInterface;
use Illuminate\Http\JsonResponse;

class MemberLoanSummaryController extends Controller
{
    public function __construct(
        protected MemberLoanSummaryServiceInterface $service,
    ) {}

    public function show(Member $member): JsonResponse
    {
        return response()->json([
            'data' => $this->service->summarize($member),
        ]);
    }
}
