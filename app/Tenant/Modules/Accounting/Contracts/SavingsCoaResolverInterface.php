<?php

namespace App\Tenant\Modules\Accounting\Contracts;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

interface SavingsCoaResolverInterface
{
    public function resolvePaymentModeAccount(string $mode): ChartOfAccount;

    public function resolveSavingsLiabilityAccount(SavingsAccount $account): ChartOfAccount;

    public function resolveByGlCode(string $glCode): ChartOfAccount;
}
