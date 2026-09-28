<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;

interface CashFlowStatementServiceInterface
{
    /**
     * Statement of cash flows for a period, with a comparison period (default: the
     * same dates a year earlier).
     */
    public function generate(Carbon $from, Carbon $to, ?Carbon $compareFrom = null, ?Carbon $compareTo = null, bool $hideZero = true, ?int $branchId = null): array;

    /**
     * The cash entries behind a line of the statement: one account's lines in the
     * entries that moved cash in the period, with the cash each brought in or paid out.
     */
    public function ledger(int $accountId, Carbon $from, Carbon $to, int $page = 1, ?int $branchId = null): array;
}
