<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Contracts\SavingsAccountStatementServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavingsAccountStatementController extends Controller
{
    public function __construct(private readonly SavingsAccountStatementServiceInterface $service) {}

    public function show(Request $request, int $savingsAccountId): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to'   => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json($this->service->buildStatement(
            $savingsAccountId,
            $validated['date_from'] ?? null,
            $validated['date_to']   ?? null,
        ));
    }
}
