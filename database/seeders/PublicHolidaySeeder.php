<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PublicHolidaySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $holidays = [
            ['description' => "New Year's Day", 'holiday_date' => '2026-01-01', 'recurrence_type' => 'yearly'],
            ['description' => 'NRM Liberation Day', 'holiday_date' => '2026-01-26', 'recurrence_type' => 'yearly'],
            ['description' => 'Archbishop Janani Luwum Day', 'holiday_date' => '2026-02-16', 'recurrence_type' => 'yearly'],
            ['description' => "International Women's Day", 'holiday_date' => '2026-03-08', 'recurrence_type' => 'yearly'],
            ['description' => 'Eid al-Fitr', 'holiday_date' => '2026-03-20', 'recurrence_type' => 'none'],
            ['description' => 'Labour Day', 'holiday_date' => '2026-05-01', 'recurrence_type' => 'yearly'],
            ['description' => 'Eid al-Adha', 'holiday_date' => '2026-05-27', 'recurrence_type' => 'none'],
            ['description' => "Martyrs' Day", 'holiday_date' => '2026-06-03', 'recurrence_type' => 'yearly'],
            ['description' => "Heroes' Day", 'holiday_date' => '2026-06-09', 'recurrence_type' => 'yearly'],
            ['description' => 'Independence Day', 'holiday_date' => '2026-10-09', 'recurrence_type' => 'yearly'],
            ['description' => 'Christmas Day', 'holiday_date' => '2026-12-25', 'recurrence_type' => 'yearly'],
            ['description' => 'Boxing Day', 'holiday_date' => '2026-12-26', 'recurrence_type' => 'yearly'],
        ];

        foreach ($holidays as $holiday) {
            DB::connection('tenant')->table('public_holidays')->updateOrInsert(
                ['description' => $holiday['description'], 'holiday_date' => $holiday['holiday_date']],
                $holiday
            );
        }
    }
}
