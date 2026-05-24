<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanStatusHistory;
use Illuminate\Validation\ValidationException;

class LoanStatusGuard
{
    /**
     * All valid status transitions.
     * Keys are the current (from) status; values are the allowed next (to) statuses.
     */
    private const TRANSITIONS = [
        'disbursed' => [
            LoanStatus::Arrears,
            LoanStatus::Closed,
            LoanStatus::WrittenOff,
        ],
        'arrears' => [
            LoanStatus::Closed,
            LoanStatus::WrittenOff,
        ],
        'closed' => [],
        'written_off' => [],
    ];

    /**
     * Check whether the given transition is allowed without throwing.
     */
    public function canTransition(Loan $loan, LoanStatus $toStatus): bool
    {
        $currentKey = $loan->status instanceof LoanStatus
            ? $loan->status->value
            : (string) $loan->status;

        $allowed = self::TRANSITIONS[$currentKey] ?? [];

        return in_array($toStatus, $allowed, true);
    }

    /**
     * Validate the transition, update the loan status, and record the history entry.
     *
     * @throws ValidationException
     */
    public function transition(Loan $loan, LoanStatus $toStatus, ?string $notes = null): void
    {
        if (! $this->canTransition($loan, $toStatus)) {
            $currentValue = $loan->status instanceof LoanStatus
                ? $loan->status->value
                : (string) $loan->status;

            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition loan from '{$currentValue}' to '{$toStatus->value}'.",
                ],
            ]);
        }

        $fromStatus = $loan->status instanceof LoanStatus
            ? $loan->status->value
            : (string) $loan->status;

        $loan->status = $toStatus;
        $loan->save();

        LoanStatusHistory::create([
            'loan_id' => $loan->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus->value,
            'changed_by' => auth('tenant')->id(),
            'notes' => $notes,
            'ip_address' => request()->ip(),
            'changed_at' => now(),
        ]);
    }
}
