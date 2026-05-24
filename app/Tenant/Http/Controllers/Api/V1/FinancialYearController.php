<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\FinancialYearFormRequest;
use App\Tenant\Modules\Settings\Models\FinancialYear;
use Illuminate\Http\Request;

class FinancialYearController extends Controller
{
    /**
     * Display a paginated listing of financial years.
     */
    public function index(Request $request)
    {
        $query = FinancialYear::query()->orderByDesc('start_date');

        if ($search = $request->input('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $financialYears = $query->paginate(15);

        return response()->json($financialYears);
    }

    /**
     * Store a newly created financial year.
     */
    public function store(FinancialYearFormRequest $request)
    {
        $financialYear = FinancialYear::create($request->validated());

        return response()->json([
            'message' => 'Financial year created successfully.',
            'data' => $financialYear,
        ], 201);
    }

    /**
     * Display the specified financial year.
     */
    public function show(FinancialYear $financialYear)
    {
        return response()->json([
            'data' => $financialYear,
        ]);
    }

    /**
     * Update the specified financial year.
     */
    public function update(FinancialYearFormRequest $request, FinancialYear $financialYear)
    {
        $financialYear->update($request->validated());

        return response()->json([
            'message' => 'Financial year updated successfully.',
            'data' => $financialYear,
        ]);
    }

    /**
     * Remove the specified financial year.
     */
    public function destroy(FinancialYear $financialYear)
    {
        $financialYear->delete();

        return response()->json([
            'message' => 'Financial year deleted successfully.',
        ]);
    }
}
