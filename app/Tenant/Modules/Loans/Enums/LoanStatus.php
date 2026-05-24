<?php

namespace App\Tenant\Modules\Loans\Enums;

enum LoanStatus: string
{
    case Disbursed = 'disbursed';

    case Active = 'active';

    case Arrears = 'arrears';

    case Closed = 'closed';

    case WrittenOff = 'written_off';
    case Rescheduled = 'rescheduled';
    case Restructured = 'restructured';

    /**
     * Human-readable label for display.
     */
    public function label(): string
    {
        return match ($this) {
            self::Disbursed => 'Disbursed',
            self::Active => 'Active',
            self::Arrears => 'In Arrears',
            self::Closed => 'Closed',
            self::WrittenOff => 'Written Off',
            self::Rescheduled => 'Rescheduled',
            self::Restructured => 'Restructured',
        };
    }
}
