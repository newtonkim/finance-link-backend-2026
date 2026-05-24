<?php

namespace App\Tenant\Modules\Savings\Contracts;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Savings\Models\SavingsAccount;

interface FdMaturityAccountingServiceInterface
{
    /**
     * DR 2113 old FD sub-ledger  CR 2113 new FD sub-ledger
     */
    public function postRollover(SavingsAccount $old, SavingsAccount $new, int $actorId): JournalEntry;

    /**
     * DR 2113 Fixed Deposits  CR 2111/2112 target savings account
     */
    public function postPayout(SavingsAccount $fd, SavingsAccount $target, int $actorId): JournalEntry;

    /**
     * DR 2113 Fixed Deposits  CR 2112 Voluntary Savings (reclassification)
     * Must be called BEFORE account_type is updated on $fd.
     */
    public function postConversion(SavingsAccount $fd, int $actorId): JournalEntry;
}
