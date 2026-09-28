<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Accounting\Contracts\CashFlowStatementServiceInterface;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashFlowStatementController extends Controller
{
    public function __construct(private readonly CashFlowStatementServiceInterface $service) {}

    /** Without dates, reports the current financial year to date (or calendar year if none is set up). */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare_from' => ['required_with:compare_to', 'date_format:Y-m-d'],
            'compare_to' => ['required_with:compare_from', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'hide_zero' => ['sometimes', 'boolean'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $to = isset($validated['to']) ? Carbon::parse($validated['to']) : Carbon::today();
        $year = FinancialYear::on('tenant')->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $to)->orderByDesc('start_date')->first();
        $from = isset($validated['from']) ? Carbon::parse($validated['from']) : ($year ? Carbon::parse($year->start_date) : $to->copy()->startOfYear());

        $result = $this->service->generate(
            $from,
            $to,
            isset($validated['compare_from']) ? Carbon::parse($validated['compare_from']) : null,
            isset($validated['compare_to']) ? Carbon::parse($validated['compare_to']) : null,
            $request->boolean('hide_zero', true),
            isset($validated['branch_id']) ? (int) $validated['branch_id'] : null,
        );
        $result['financial_year'] = $year ? ['name' => $year->name, 'start_date' => Carbon::parse($year->start_date)->toDateString(), 'end_date' => Carbon::parse($year->end_date)->toDateString()] : null;
        $result['period_default'] = $year ? 'financial_year' : 'calendar_year';

        return response()->json($result);
    }
}
