<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;

interface TrialBalanceServiceInterface
{
    /**
     * Returns all GL accounts with cumulative DR/CR totals up to $date.
     * Opening DR/CR are always 0 in this mode.
     */
    public function asOfDate(Carbon $date): array;

    /**
     * Returns all GL accounts with:
     *  - opening: cumulative DR/CR before $from
     *  - period:  DR/CR movements between $from and $to (inclusive)
     *  - closing: opening + period net
     */
    public function forPeriod(Carbon $from, Carbon $to): array;

    /**
     * Paginated GL lines for a single account within a date range.
     * Includes a running_balance per line.
     */
    public function ledgerLines(int $accountId, Carbon $from, Carbon $to, int $page = 1): LengthAwarePaginator;
}
