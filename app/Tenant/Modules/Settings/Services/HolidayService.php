<?php

namespace App\Tenant\Modules\Settings\Services;

use App\Tenant\Modules\Settings\Models\PublicHoliday;
use Carbon\Carbon;

class HolidayService
{
    /**
     * Check if a given date is a holiday.
     * Includes automated calculation of Easter-related holidays.
     */
    public function isHoliday(Carbon $date): bool
    {
        // 1. Check database holidays (including recurring M-D matches)
        $holidays = PublicHoliday::all();
        foreach ($holidays as $holiday) {
            if ($holiday->matchesDate($date)) {
                return true;
            }
        }

        // 2. Automated Generator: Dynamic Holidays (no DB entry needed)
        if ($this->isEasterRelatedHoliday($date)) {
            return true;
        }

        return false;
    }

    /**
     * Get the next available working day from a given date.
     * Skips holidays and weekends.
     */
    public function getNextWorkingDay(Carbon $date): Carbon
    {
        $checkDate = $date->copy();

        // While it's a holiday or a weekend (Saturday/Sunday), move to next day
        while ($this->isHoliday($checkDate) || $checkDate->isWeekend()) {
            $checkDate->addDay();
        }

        return $checkDate;
    }

    /**
     * Logic to calculate Easter-related holidays for any given year.
     * Includes: Good Friday (Easter - 2), Easter Monday (Easter + 1).
     */
    private function isEasterRelatedHoliday(Carbon $date): bool
    {
        $year = $date->year;

        // PHP built-in easter_days returns days after March 21
        $easterDays = easter_days($year);
        $easterDate = Carbon::create($year, 3, 21)->addDays($easterDays);

        $goodFriday = $easterDate->copy()->subDays(2);
        $easterMonday = $easterDate->copy()->addDay();

        return $date->isSameDay($goodFriday) || $date->isSameDay($easterMonday);
    }
}
