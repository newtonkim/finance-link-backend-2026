<?php

namespace App\Tenant\Modules\Settings\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $connection = 'tenant';

    protected $table = 'public_holidays';

    protected $fillable = [
        'holiday_date',
        'description',
        'recurrence_type',
        'loan_repayment_schedule_id',
        'loan_id',
        'branch_id',
        'created_by',
    ];

    protected $casts = [
        'holiday_date' => 'date',
    ];

    /**
     * Check if a given date is this holiday.
     * Handles recurring holidays by ignoring the year if recurrence_type is 'yearly'.
     */
    public function matchesDate($date): bool
    {
        $holidayDate = Carbon::parse($this->holiday_date);
        $checkDate = Carbon::parse($date);

        if ($this->recurrence_type === 'yearly') {
            return $holidayDate->format('m-d') === $checkDate->format('m-d');
        }

        return $holidayDate->toDateString() === $checkDate->toDateString();
    }
}
