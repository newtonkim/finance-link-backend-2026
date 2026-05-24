<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Accounting\Models\AccountingPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingPeriodController extends Controller
{
    /**
     * Get recent accounting periods and their lock statuses.
     */
    public function index(Request $request): JsonResponse
    {
        // Fetch periods from the last 12 months as a standard reference
        $periods = [];
        $currentDate = now()->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $periodCode = $currentDate->copy()->subMonths($i)->format('Y-m');
            $periods[$periodCode] = [
                'period_code' => $periodCode,
                'is_locked' => false,
                'locked_by' => null,
                'locked_at' => null,
            ];
        }

        $existingPeriods = AccountingPeriod::on('tenant')
            ->whereIn('period_code', array_keys($periods))
            ->get()
            ->keyBy('period_code');

        foreach ($existingPeriods as $code => $record) {
            $periods[$code] = [
                'id' => $record->id,
                'period_code' => $record->period_code,
                'is_locked' => $record->is_locked,
                'locked_by' => $record->lockedBy?->name ?? 'System',
                'locked_at' => $record->locked_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => array_values($periods),
        ]);
    }

    /**
     * Toggle the lock status of a period.
     */
    public function toggleLock(Request $request): JsonResponse
    {
        $request->validate([
            'period_code' => ['required', 'string', 'size:7', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $periodCode = $request->period_code;
        $userId = auth()->id();

        $period = AccountingPeriod::on('tenant')->firstOrNew(['period_code' => $periodCode]);

        $period->is_locked = !$period->is_locked;

        if ($period->is_locked) {
            $period->locked_by = $userId;
            $period->locked_at = now();
        } else {
            $period->locked_by = null;
            $period->locked_at = null;
        }

        $period->save();

        return response()->json([
            'status' => 'success',
            'message' => $period->is_locked ? "Period {$periodCode} locked." : "Period {$periodCode} unlocked.",
            'data' => [
                'period_code' => $period->period_code,
                'is_locked' => $period->is_locked,
                'locked_by' => $period->lockedBy?->name ?? 'System',
                'locked_at' => $period->locked_at?->toIso8601String(),
            ],
        ]);
    }
}
