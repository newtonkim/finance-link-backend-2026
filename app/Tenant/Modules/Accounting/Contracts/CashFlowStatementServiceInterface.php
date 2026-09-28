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
}
