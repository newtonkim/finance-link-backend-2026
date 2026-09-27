<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Services\NotificationService;
use App\Tenant\Support\TenantMoney;
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

    private function send(LoanApplicationGuarantor $pledge, callable $body): void
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

        DB::connection('tenant')->afterCommit(fn () => $notify->runTheQue());
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
