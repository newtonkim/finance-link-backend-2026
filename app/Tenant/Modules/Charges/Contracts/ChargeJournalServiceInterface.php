<?php

namespace App\Tenant\Modules\Charges\Contracts;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

interface ChargeJournalServiceInterface
{
    public function post(
        GeneralCharge $charge,
        float $fee,
        int $memberId,
        SavingsAccount $savingsAccount,
        string $reference,
        int $postedBy,
    ): JournalEntry;
}
