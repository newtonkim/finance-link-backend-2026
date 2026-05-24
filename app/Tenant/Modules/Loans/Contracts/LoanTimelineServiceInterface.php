<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use Illuminate\Support\Collection;

interface LoanTimelineServiceInterface
{
    /**
     * Return a chronological list of all events on the given application.
     *
     * Each item is an array with keys:
     *  - type        string  (status_change | document_uploaded | approval_vote | created)
     *  - title       string  Short human-readable title
     *  - description string  Full description
     *  - actor       array|null  ['id' => int, 'name' => string]
     *  - notes       string|null
     *  - timestamp   Carbon
     *
     * @return Collection<int, array>
     */
    public function getTimeline(LoanApplication $application): Collection;
}
