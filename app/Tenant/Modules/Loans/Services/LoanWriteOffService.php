<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\LoanAccountingService;
use App\Tenant\Modules\Loans\Contracts\LoanWriteOffServiceInterface;
use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LoanWriteOffService implements LoanWriteOffServiceInterface
{
    public function __construct(
        private readonly LoanStatusGuard $guard,
        private readonly LoanAccountingService $accounting,
    ) {}

    public function writeOff(Loan $loan, int $actorId, string $narration = ''): void
    {
        $allowedStatuses = [LoanStatus::Arrears, LoanStatus::Disbursed, LoanStatus::Active];

        if (! in_array($loan->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'loan' => ['Only loans in arrears, disbursed, or active status can be written off.'],
            ]);
        }

        $outstanding = (float) $loan->outstanding_balance;

        if ($outstanding <= 0) {
            throw ValidationException::withMessages([
                'loan' => ['Cannot write off a loan with zero outstanding balance.'],
            ]);
        }

        $loan->loadMissing('loanProduct');
        $portfolioAccountId = $loan->loanProduct?->loan_portfolio_account_id;

        if (! $portfolioAccountId) {
            throw ValidationException::withMessages([
                'loan' => ['Loan product has no loan portfolio GL account configured.'],
            ]);
        }

        $writeOffAccountId = ChartOfAccount::where('gl_code', GlCodes::EXPENSE_LOAN_WRITE_OFF)
            ->where('is_active', true)
            ->value('id');

        if (! $writeOffAccountId) {
            throw ValidationException::withMessages([
                'loan' => ['Write-off expense account (GL '.GlCodes::EXPENSE_LOAN_WRITE_OFF.') is not configured in chart of accounts.'],
            ]);
        }

        DB::connection('tenant')->transaction(function () use ($loan, $outstanding, $portfolioAccountId, $writeOffAccountId, $actorId, $narration) {
            $this->accounting->postJournalEntry(
                loan: $loan,
                typeCode: 'LOAN_WRITE_OFF',
                narration: $narration ?: "Loan write-off — {$loan->loan_number}",
                lines: [
                    $this->accounting->line($writeOffAccountId, $outstanding, 0.0, 'Write-off expense', $loan->id),
                    $this->accounting->line($portfolioAccountId, 0.0, $outstanding, 'Loan portfolio reduction', $loan->id),
                ],
                date: Carbon::today(),
                actorId: $actorId,
            );

            $this->guard->transition($loan, LoanStatus::WrittenOff, $narration ?: 'Written off');
        });
    }
}
