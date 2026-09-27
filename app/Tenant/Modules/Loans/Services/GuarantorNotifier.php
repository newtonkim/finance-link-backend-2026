<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\GuarantorRecovery;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Services\NotificationService;
use App\Tenant\Support\TenantMoney;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SMS to guarantors. A group guarantor's message goes to every member of the group.
 *
 * Messages are queued in the same transaction as the change that caused them, so a
 * rolled-back save sends nothing; the send job is dispatched only after commit.
 * Whether anything is sent at all is still governed by the SACCO's
 * "notify the guarantor" setting inside NotificationService.
 */
class GuarantorNotifier
{
    /** The guarantor was recorded and nothing is asked of them. */
    public function added(LoanApplicationGuarantor $pledge): void
    {
        $this->send($pledge, function (object $recipient, object $application, bool $isGroup, ?string $guarantorName) {
            return $isGroup
                ? "Hello {$recipient->name}, your group {$guarantorName} has been added as a guarantor for loan application {$application->application_no}. The applicant is {$application->name}."
                : "Hello {$recipient->name}, you have been added as a guarantor for loan application {$application->application_no}. The applicant is {$application->name}.";
        });
    }

    /** The guarantor is asked to accept or decline before the request expires. */
    public function consentRequested(LoanApplicationGuarantor $pledge): void
    {
        $amount = TenantMoney::format($pledge->guarantee_amount);
        $deadline = $pledge->consent_expires_at?->format('j M Y');

        $this->send($pledge, function (object $recipient, object $application, bool $isGroup, ?string $guarantorName) use ($amount, $deadline) {
            return $isGroup
                ? "Hello {$recipient->name}, your group {$guarantorName} has been asked to guarantee {$amount} on loan application {$application->application_no} for {$application->name}. Please give the SACCO the group's decision by {$deadline}."
                : "Hello {$recipient->name}, {$application->name} has asked you to guarantee {$amount} on loan application {$application->application_no}. Log in to the member portal to accept or decline by {$deadline}.";
        });
    }

    /**
     * The loan the guarantor stands behind is overdue.
     *
     * @param  array{loan_no: string, days_past_due: int, arrears_amount: float}  $arrears
     * @param  string|null  $subdomain  the tenant, when sending from a scheduled command
     */
    public function arrears(LoanApplicationGuarantor $pledge, array $arrears, ?string $subdomain = null): void
    {
        $overdue = TenantMoney::format($arrears['arrears_amount']);
        $held = TenantMoney::format($pledge->guarantee_amount);
        $days = (int) $arrears['days_past_due'];

        $this->send($pledge, function (object $recipient, object $application, bool $isGroup, ?string $guarantorName) use ($arrears, $overdue, $held, $days) {
            $whose = $isGroup ? "your group {$guarantorName}'s" : 'your';

            return "Hello {$recipient->name}, loan {$arrears['loan_no']} of {$application->name}, which {$whose} savings guarantee, is {$days} days overdue with {$overdue} unpaid. {$held} of {$whose} savings is held for it. Please encourage them to pay.";
        }, $subdomain);
    }

    /** Another guarantor has taken over, so this one's savings are no longer held. */
    public function replaced(LoanApplicationGuarantor $pledge, ?string $replacementName): void
    {
        $loanNo = $pledge->loan?->loan_no;

        $this->send($pledge, function (object $recipient, object $application, bool $isGroup) use ($loanNo, $replacementName) {
            $whose = $isGroup ? 'your group\'s' : 'your';
            $by = $replacementName ? " by {$replacementName}" : '';

            return "Hello {$recipient->name}, you have been replaced{$by} as guarantor of loan {$loanNo} of {$application->name}. ".ucfirst($whose).' savings are no longer held for it.';
        });
    }

    /** The loan was rescheduled, so the guarantor stands behind it for longer. */
    public function rescheduled(LoanApplicationGuarantor $pledge, string $loanNo, int $termMonths, ?string $maturityDate): void
    {
        $until = $maturityDate ? Carbon::parse($maturityDate)->format('j M Y') : null;

        $this->send($pledge, function (object $recipient, object $application) use ($loanNo, $termMonths, $until) {
            $ends = $until ? " and now runs until {$until}" : '';

            return "Hello {$recipient->name}, loan {$loanNo} of {$application->name}, which you guarantee, has been rescheduled to {$termMonths} months{$ends}. Your guarantee continues. If you do not want to go on guaranteeing it, ask the SACCO to replace you.";
        });
    }

    /** The SACCO took $amount from the guarantor's savings to repay the loan. */
    public function recovered(LoanApplicationGuarantor $pledge, float $amount, string $loanNo): void
    {
        $taken = TenantMoney::format($amount);

        $this->send($pledge, function (object $recipient, object $application) use ($taken, $loanNo) {
            return "Hello {$recipient->name}, {$taken} was taken from your savings to repay loan {$loanNo} of {$application->name}, which you guaranteed. {$application->name} now owes you this and will repay it to you through the SACCO.";
        });
    }

    /** Tells the borrower they now owe their guarantors what the guarantors paid. */
    public function recoveryLoanCreated(GuarantorRecovery $recovery, string $loanNo, float $instalment): void
    {
        $member = DB::table('members')->where('id', $recovery->member_id)->first(['name', 'code', 'phone', 'email', 'id']);

        if (! $member) {
            return;
        }

        $owed = TenantMoney::format($recovery->guarantor_amount);
        $monthly = TenantMoney::format($instalment);
        $body = "Hello {$member->name}, your guarantors paid {$owed} toward loan {$loanNo}. You now owe them this as recovery loan {$recovery->code}, repayable at {$monthly} a month through the SACCO.";

        $notify = new NotificationService;

        try {
            $notify->sendNotification(
                'saccoNotifyTheGuarantor',
                $body,
                $member,
                ['type' => 'recovery-loan', 'id' => $recovery->id, 'loan_id' => $recovery->loan_id],
                'sms',
                'guarantor-notification',
                true,
                false
            );
        } catch (\Throwable $e) {
            Log::warning('Could not queue recovery loan SMS', ['guarantor_recovery_id' => $recovery->id, 'error' => $e->getMessage()]);
        }

        DB::connection('tenant')->afterCommit(fn () => $notify->runTheQue());
    }

    private function send(LoanApplicationGuarantor $pledge, callable $body, ?string $subdomain = null): void
    {
        $application = DB::table('loan_applications')->where('loan_applications.id', $pledge->loan_application_id)
            ->join('members as mb', 'loan_applications.member_id', '=', 'mb.id')
            ->first(['application_no', 'mb.name']);

        if (! $application) {
            return;
        }

        $isGroup = $pledge->guarantor_type === LoanApplicationGuarantor::TYPE_GROUP;
        $guarantorName = $pledge->guarantorName();

        $recipients = $isGroup
            ? DB::table('savings_group_members as sgm')
                ->join('members as mb', 'sgm.member_id', '=', 'mb.id')
                ->where('sgm.savings_group_id', $pledge->guarantor_id)
                ->get(['mb.name', 'mb.code', 'mb.phone', 'mb.email', 'mb.id'])
            : DB::table('members as mb')
                ->where('mb.id', $pledge->guarantor_id)
                ->get(['mb.name', 'mb.code', 'mb.phone', 'mb.email', 'mb.id']);

        if ($recipients->isEmpty()) {
            return;
        }

        $notify = new NotificationService;

        foreach ($recipients as $recipient) {
            try {
                $this->queue($notify, $pledge, $recipient, $body($recipient, $application, $isGroup, $guarantorName), $guarantorName);
            } catch (\Throwable $e) {
                // A message that cannot be queued must not undo the guarantee itself.
                Log::warning('Could not queue guarantor SMS', [
                    'loan_application_guarantor_id' => $pledge->id,
                    'member_id' => $recipient->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::connection('tenant')->afterCommit(fn () => $notify->runTheQue($subdomain));
    }

    private function queue(NotificationService $notify, LoanApplicationGuarantor $pledge, object $recipient, string $body, ?string $guarantorName): void
    {
        $notify->sendNotification(
            'saccoNotifyTheGuarantor',
            $body,
            $recipient,
            [
                'type' => $pledge->guarantor_type,
                'id' => $pledge->guarantor_id,
                'application_id' => $pledge->loan_application_id,
                'guarantor_id' => $pledge->guarantor_id,
                'guarantor_name' => $guarantorName,
                'guarantor_type' => $pledge->guarantor_type,
            ],
            'sms',
            'guarantor-notification',
            true,
            false
        );
    }
}
