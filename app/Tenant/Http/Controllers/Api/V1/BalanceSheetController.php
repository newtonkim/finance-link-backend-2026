<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\BalanceSheetLineResource;
use App\Tenant\Modules\Accounting\Contracts\BalanceSheetServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceSheetController extends Controller
{
    public function __construct(
        private readonly BalanceSheetServiceInterface $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Default standalone requests to today; comparison periods may be in either order.
        $request->mergeIfMissing(['as_at' => Carbon::today()->toDateString()]);

        $validated = $request->validate([
            'as_at' => ['required', 'date'],
            'compare_to' => ['nullable', 'date'],
            'hide_zero' => ['nullable', 'boolean'],
        ]);

        $result = $this->service->generate(
            Carbon::parse($validated['as_at']),
            isset($validated['compare_to']) ? Carbon::parse($validated['compare_to']) : null,
            $request->boolean('hide_zero', true),
        );

        $result['sections'] = array_map(fn (array $section) => [
            ...$section,
            'lines' => BalanceSheetLineResource::collection(collect($section['lines']))->resolve($request),
        ], $result['sections']);

        return response()->json($result);
    }
}
