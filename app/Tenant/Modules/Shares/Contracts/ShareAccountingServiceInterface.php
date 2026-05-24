<?php

namespace App\Tenant\Modules\Shares\Contracts;

use App\Tenant\Modules\Shares\Models\Share;

interface ShareAccountingServiceInterface
{
    /**
     * Post DR Cash / CR Share Capital journal entry for a share purchase.
     */
    public function postSharePurchaseEntry(Share $share, ?int $actorId): void;
}
