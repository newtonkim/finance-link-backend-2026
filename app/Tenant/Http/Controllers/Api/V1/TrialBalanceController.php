<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\LedgerLineResource;
use App\Tenant\Http\Resources\TrialBalanceRowResource;
use App\Tenant\Modules\Accounting\Contracts\TrialBalanceServiceInterface;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialBalanceController extends Controller
{
    public function __construct(
        private readonly TrialBalanceServiceInterface $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'date' => ['nullable', 'date'],
            'from' => ['nullable', 'date', 'required_with:to'],
            'to'   => ['nullable', 'date', 'required_with:from', 'after_or_equal:from'],
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            $result = $this->service->forPeriod(
                Carbon::parse($request->input('from')),
                Carbon::parse($request->input('to')),
            );
        } else {
            $date = $request->filled('date')
                ? Carbon::parse($request->input('date'))
                : Carbon::today();

            $result = $this->service->asOfDate($date);
        }

        $result['accounts'] = TrialBalanceRowResource::collection(collect($result['accounts']))->resolve();

        return response()->json($result);
    }

    public function ledger(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'account_id' => ['required', 'integer', 'exists:tenant.chart_of_accounts,id'],
            'from'       => ['required', 'date'],
            'to'         => ['required', 'date', 'after_or_equal:from'],
            'page'       => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->service->ledgerLines(
            (int)   $validated['account_id'],
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to']),
            (int) ($validated['page'] ?? 1),
        );

        return response()->json([
            'data'         => LedgerLineResource::collection(collect($paginator->items()))->resolve(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'total'        => $paginator->total(),
            'per_page'     => $paginator->perPage(),
        ]);
    }
}
