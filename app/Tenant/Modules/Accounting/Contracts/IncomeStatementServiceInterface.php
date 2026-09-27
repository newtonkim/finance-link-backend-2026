<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use Carbon\Carbon;

interface IncomeStatementServiceInterface
{
    public function generate(Carbon $from, Carbon $to, ?Carbon $compareFrom = null, ?Carbon $compareTo = null, bool $hideZero = true, ?int $branchId = null): array;

    public function ledger(int $accountId, Carbon $from, Carbon $to, int $page = 1, ?int $branchId = null): array;
}
