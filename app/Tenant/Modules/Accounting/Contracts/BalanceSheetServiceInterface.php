<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;

interface BalanceSheetServiceInterface
{
    /**
     * Comparative Statement of Financial Position.
     *
     * Sections are built from the chart of accounts hierarchy (parent_id). Because
     * no year-end closing exists, income/expense balances are surfaced as computed
     * surplus lines under Retained Earnings so that Assets = Liabilities + Equity.
     */
    public function generate(Carbon $asAt, ?Carbon $compareTo = null, bool $hideZero = true): array;

    /**
     * Day before the start of the financial year containing $asAt,
     * or 31 Dec of the previous year when no financial year matches.
     */
    public function defaultCompareDate(Carbon $asAt): Carbon;
}
