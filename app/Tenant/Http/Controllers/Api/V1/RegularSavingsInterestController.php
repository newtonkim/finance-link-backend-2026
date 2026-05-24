<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Services\RegularSavingsInterestService;
use Illuminate\Http\JsonResponse;

class RegularSavingsInterestController extends Controller
{
    public function __construct(
        protected RegularSavingsInterestService $service,
    ) {}

    public function postInterest(): JsonResponse
    {
        $actorId = auth()->id() ?? 1;
        $summary = $this->service->runBatchPosting($actorId);

        return response()->json([
            'message' => 'Interest posting complete',
            'summary' => $summary,
        ]);
    }
}
