<?php

namespace App\Tenant\Modules\Loans\Concerns;

trait FormatsLoanActivity
{
    private function staffSummary($staff): array
    {
        return [
            'id' => $staff->id,
            'name' => trim($staff->first_name.' '.$staff->last_name),
        ];
    }

    private function labelStatus(string $status): string
    {
        return match ($status) {
            'draft' => 'Draft',
            'submitted' => 'Submitted',
            'under_review' => 'Under Review',
            'awaiting_documents' => 'Awaiting Documents',
            'recommended' => 'Recommended',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'disbursement_pending' => 'Disbursement Pending',
            'disbursed' => 'Disbursed',
            'cancelled' => 'Cancelled',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }
}
