<?php

namespace App\Tenant\Modules\Shares\Services;

use App\Models\Member;
use App\Tenant\Modules\Accounting\GlCodes;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Models\JournalEntryLine;
use App\Tenant\Modules\Accounting\Services\GlPostingEngine;
use App\Tenant\Modules\Accounting\Services\JournalSequenceService;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use App\Tenant\Modules\Shares\Contracts\ShareAccountingServiceInterface;
use App\Tenant\Modules\Shares\Models\Share;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ShareAccountingService implements ShareAccountingServiceInterface
{
    public function __construct(
        private readonly JournalSequenceService $sequence,
        private readonly GlPostingEngine $gl,
    ) {}

    /**
     * Post the double-entry journal entry for a share purchase.
     *
     *   DR  Petty Cash (1111)               = total_value
     *   CR  Ordinary Share Capital (31100)  = total_value
     *
     * Silently skips if either GL account is not seeded in the chart of accounts.
     */
    public function postSharePurchaseEntry(Share $share, ?int $actorId): void
    {
        $settings = OnboardingSettings::current();
        $cashGl = $settings->share_payment_account_id !== null
            ? ChartOfAccount::on('tenant')->find($settings->share_payment_account_id)
            : null;
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', GlCodes::SHARE_CAPITAL_ORDINARY)->first();

        if (! $cashGl || ! $shareCapitalGl) {
            Log::warning('ShareAccountingService: share payment account or GL '.GlCodes::SHARE_CAPITAL_ORDINARY.' not configured — share purchase JE skipped.', [
                'share_id' => $share->id,
                'member_id' => $share->member_id,
                'share_payment_account_id' => $settings->share_payment_account_id,
            ]);

            return;
        }

        $amount = (float) $share->total_value;
        $date = Carbon::parse($share->purchased_at ?? now());
        $narration = "Share purchase – member #{$share->member_id}";
        $reference = "SHR-{$share->id}";

        $entryNo = $this->sequence->nextEntryNo('SHARE');

        $je = JournalEntry::create([
            'entry_no' => $entryNo,
            'date' => $date,
            'period_date' => $date,
            'fiscal_period' => $date->format('Y-m'),
            'journal_type' => 'share',
            'reference' => $reference,
            'reference_type' => 'share',
            'narration' => $narration,
            'status' => 'posted',
            'is_system' => true,
            'posted_by' => $actorId,
            'posted_at' => now(),
            'branch_id' => null,
        ]);

        $lines = [
            ['accountId' => $cashGl->id, 'normalBalance' => $cashGl->normal_balance, 'debit' => $amount, 'credit' => 0.0],
            ['accountId' => $shareCapitalGl->id, 'normalBalance' => $shareCapitalGl->normal_balance, 'debit' => 0.0, 'credit' => $amount],
        ];

        foreach ($lines as $lineNo => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $je->id,
                'account_id' => $line['accountId'],
                'debit' => $line['debit'],
                'credit' => $line['credit'],
                'narration' => $narration,
                'loan_id' => null,
                'member_id' => $share->member_id,
                'branch_id' => null,
                'line_no' => $lineNo + 1,
            ]);

            $this->gl->postToGeneralLedger($je->id, $line['accountId'], $line['debit'], $line['credit'], $date, $narration, $line['normalBalance']);
            $this->gl->postToSubLedger($je->id, $line['accountId'], $share->member_id, Member::class, $line['debit'], $line['credit'], $date, $narration, $line['normalBalance']);
        }
    }
}
