<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Http\Resources\PublicHolidayResource;
use App\Tenant\Modules\Settings\Models\LoanSetting;
use App\Tenant\Modules\Settings\Models\PublicHoliday;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PublicHolidayController extends Controller
{
    public function index()
    {
        $holidays = PublicHoliday::orderBy('holiday_date')->get();

        $loanSettings = LoanSetting::currentForBranch(Auth::user()->branch_id ?? 1);

        return response()->json([
            'data' => PublicHolidayResource::collection($holidays),
            'settings' => [
                'push_installments_on_holidays' => (bool) $loanSettings->push_installments_on_holidays,
                'push_installments_on_holidays_weekdays_only' => (bool) ($loanSettings->push_installments_on_holidays_weekdays_only ?? false),
                'relative_scheduling' => (bool) ($loanSettings->relative_scheduling ?? false),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'date' => 'required|date',
            'recurring' => 'boolean',
        ]);

        $holiday = PublicHoliday::create([
            'description' => $validated['name'],
            'holiday_date' => $validated['date'],
            'recurrence_type' => $validated['recurring'] ? 'yearly' : 'none',
            'branch_id' => Auth::user()->branch_id ?? null,
            'created_by' => Auth::id(),
        ]);

        return new PublicHolidayResource($holiday);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'push_installments_on_holidays' => 'required|boolean',
            'push_installments_on_holidays_weekdays_only' => 'sometimes|boolean',
            'relative_scheduling' => 'sometimes|boolean',
        ]);

        $loanSettings = LoanSetting::currentForBranch(Auth::user()->branch_id ?? 1);

        $payload = [
            'push_installments_on_holidays' => $validated['push_installments_on_holidays'],
            'push_installments_on_holidays_weekdays_only' => $validated['push_installments_on_holidays_weekdays_only']
                ?? (bool) ($loanSettings->push_installments_on_holidays_weekdays_only ?? false),
            'relative_scheduling' => $validated['relative_scheduling']
                ?? (bool) ($loanSettings->relative_scheduling ?? false),
        ];

        try {
            $loanSettings->update($payload);
        } catch (QueryException $e) {
            if (! LoanSetting::isHolidaySchedulingMissingColumnError($e)) {
                throw $e;
            }

            // Backward compatibility for tenants not yet migrated with the new columns.
            unset($payload['push_installments_on_holidays_weekdays_only'], $payload['relative_scheduling']);
            $loanSettings->update($payload);
        }

        return response()->json(['message' => 'Settings updated successfully']);
    }

    public function update(Request $request, PublicHoliday $publicHoliday)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string',
            'date' => 'sometimes|required|date',
            'recurring' => 'sometimes|boolean',
        ]);

        $updateData = [];
        if (isset($validated['name'])) {
            $updateData['description'] = $validated['name'];
        }
        if (isset($validated['date'])) {
            $updateData['holiday_date'] = $validated['date'];
        }
        if (isset($validated['recurring'])) {
            $updateData['recurrence_type'] = $validated['recurring'] ? 'yearly' : 'none';
        }

        $publicHoliday->update($updateData);

        return new PublicHolidayResource($publicHoliday);
    }

    public function destroy(PublicHoliday $publicHoliday)
    {
        $publicHoliday->delete();

        return response()->noContent();
    }
}
