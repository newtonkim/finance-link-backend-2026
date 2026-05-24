<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;
use App\Jobs\SendQueuedNotificationsAndMessages;
use App\Tenant\Services\MemebersSettingSevices\CodeSequence;
use App\Tenant\Services\MemebersSettingSevices\FindsettingsAction;
use App\Tenant\Services\TenantSavingsAcountServices\CrudHelders;
use Illuminate\Support\Facades\DB;

class NotificationService extends GlobalHelpers
{
    /**
     * $other_data = [], $type = 'sms', $receiver_id, $transaction_type = 'guarantor-notification', $transactionInsert = true, $send = true
     * Sends a notification only if the related SACCO setting is enabled.
     *
     * This method acts as a dynamic gateway for all system notifications.
     * It checks a configuration method (based on $method) and only queues
     * the notification if the feature flag is enabled.
     *
     * ------------------------------------------------------------
     * PARAMETERS (forwarded to QueNotifications via ...$params)
     * ------------------------------------------------------------
     *
     * @param  string  $method
     *                          The settings method name used to determine if notification is allowed.
     *                          Example:
     *                          - saccoOnLoanApproval
     *                          - saccoOnDepositTransaction
     * @param  mixed  ...$params
     *                            Variable arguments forwarded directly to QueNotifications():
     *
     *   Expected structure:
     *   [
     *      $body,               // string - message content
     *      $other_data = [],    // array - extra metadata
     *      $type = 'sms',       // string - notification channel (sms/email/push)
     *      $receiver_id,        // int|string - recipient identifier
     *      $transaction_type = 'guarantor-notification',
     *      $transactionInsert = true,
     *      $send = true
     *   ]
     *
     * ------------------------------------------------------------
     * AVAILABLE FEATURE FLAGS (METHODS)
     * ------------------------------------------------------------
     *
     * Each method below returns TRUE/FALSE from FindsettingsAction
     * and controls whether a notification is allowed:
     *
     * - saccoOnSellOfShares
     * - saccoOnMembershipChange
     * - saccoOnLoanClosing
     * - saccoOnLoanPayment
     * - saccoOnLoanDueDateWarning
     * - saccoOnCommitteeActionOnLoan
     * - saccoOnOpeningAnotherAccount
     * - saccoOnLoanWritingOff
     * - saccoOnLoanWaivingOff
     * - saccoOnLoanRejecting
     * - saccoOnLoanDisbursement
     * - saccoOnLoanApproval
     * - saccoOnLoanAppraisal
     * - saccoOnLoanApplication
     * - saccoOnDepositTransaction
     * - saccoOnTransferTransaction
     * - saccoOnWithdrawTransaction
     * - saccoOnNewMemberRegistration
     * -saccoNotifyTheGuarantor
     *
     * ------------------------------------------------------------
     * FLOW
     * ------------------------------------------------------------
     * 1. Dynamically call settings method using $method
     * 2. If enabled (returns sent_message_notifications table details):
     *      → queue notification via QueNotifications()
     * 3. If disabled:
     *      → do nothing (returns {})
     */
    public function sendNotification($method, ...$params)
    {
        // config(['database.default' => 'tenant']);
        $dd = new FindsettingsAction(['system-sms-notifications']);

        return $dd->{$method}() ? $this->QueNotifications(...$params) : (object) [];
    }

    public function runTheQue()
    {

        SendQueuedNotificationsAndMessages::dispatch(request()->header('X-Tenant-Subdomain')); // trigger job
    }

    public function QueNotifications(
        $body,
        $receiver_id,
        $other_data = [],
        $type = 'sms',
        $transaction_type = 'guarantor-notification',
        $transactionInsert = true,
        $send = true
    ) {
        return $this->TryCatch(function () use ($body, $other_data, $type, $receiver_id, $transaction_type, $transactionInsert, $send) {
            $CrudHelders = new CrudHelders;
            $GlobalHelpers = new GlobalHelpers;
            $codeSequence = new CodeSequence;
            $length = strlen($body);
            $getCost = DB::table('message_notification_settings')->where('length_min', '<=', $length)->where('length_max', '>=', $length)->where('channel', $type)->first();
            $billing = DB::table('tenant_billing_account')->where('channel', 'sms')->first(['account_balance as cost', 'channel', 'id']);
            $max = (int) $getCost->length_max;
            $charge = ($max == 0) ? 0 : ceil($length / $max) * $max;
            $fieldColumn = [
                'code' => $codeSequence->codeSequence(null),
                'title' => 'Loan Guarantor' ?? null,
                'body' => $body,
                'str_length' => $length,
                'cost' => $charge ?? null,
                'from_module' => 'loan-application-guarantor',
                'other_data' => [
                    'notification_settings' => $getCost,
                    ...$other_data,
                ],
                'channel' => $type,
                'sender_id' => auth()->user()->id,
                'receiver_id' => $receiver_id->phone ?? $receiver_id->email,
            ];
            if (isset($getCost) && $getCost->channel == 'sms') {
                if ($billing->cost >= $getCost->cost) {
                    $charge = $getCost->cost;
                    DB::table('tenant_billing_account')->where('id', $billing->id)->decrement('account_balance', $charge);
                    $fieldColumn['cost'] = $charge;
                    $fieldColumn['status'] = 'pending';
                } else {
                    $fieldColumn['status'] = 'no-funds';
                    $fieldColumn['reason_for_failure'] = 'no-funds BLC '.$billing->cost;
                }
            }
            $detail = $GlobalHelpers->UpdateOrCreateRecord('sent_message_notifications', $fieldColumn);
            if (isset($detail->error) && $detail->error) {
                throw new \Exception($detail->error);
            }
            if ($transactionInsert) {
                $TransactionData = $CrudHelders->transactionUorCFields([
                    'reference' => $detail->code,
                    'member' => $receiver_id->id,
                    'transaction_type' => $transaction_type,
                    'amount' => $charge,
                    'charge_amount' => 0,
                    'payment_method' => 'notification',
                    'deposited_by' => 'System (Initial Deposit)',
                    'transaction_date' => now()->toDateString(),
                    'b4trn' => "$billing->cost",
                    'narration' => $body,
                ]);
                $GlobalHelpers->UpdateOrCreateRecord('transactions', $TransactionData);
            }
            if ($send) {
                $this->runTheQue();
            } // trigger job

            return $detail;
        });
    }
}
