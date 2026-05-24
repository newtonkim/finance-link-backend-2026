<?php

namespace App\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nugsoft\SignalBridge\Facades\SignalBridge;
use Throwable;

class SendQueuedNotificationsAndMessages implements ShouldQueue
{
    use Dispatchable, Queueable;

    protected $subdomainName;

    public function __construct($subdomainName = null)
    {
        $this->subdomainName = $subdomainName;
    }

    public function handle(): void
    {
        if (empty($this->subdomainName)) {
            throw new Exception('Subdomain is required');
        }

        $dbName = 'sacco_'.Str::slug($this->subdomainName, '_');
        config(['database.connections.mysql.database' => $dbName]);

        DB::purge('mysql');
        DB::reconnect('mysql');

        $db = DB::connection('mysql');

        Log::info('Using tenant DB', [
            'database' => $dbName,
        ]);

        $db->table('sent_message_notifications')
            ->select(['id', 'body', 'sender_id', 'receiver_id', 'title', 'channel'])
            ->where('status', 'pending')
            ->whereIn('channel', ['email', 'sms'])
            ->orderBy('created_at', 'asc')
            ->chunk(100, function ($messages) use ($db) {
                foreach ($messages as $message) {
                    try {

                        if ($message->channel === 'email') {
                            SignalBridge::sendEmail(
                                $message->receiver_id,
                                $message->title,
                                $message->body
                            );
                        } else {
                            SignalBridge::sendSms(
                                recipient: $message->receiver_id,
                                message: $message->body,
                                options: [
                                    'metadata' => ['sender_id' => $message->sender_id],
                                ]
                            );
                        }

                        $db->table('sent_message_notifications')
                            ->where('id', $message->id)
                            ->update(['status' => 'sent']);
                    } catch (Throwable $e) {

                        $db->table('sent_message_notifications')
                            ->where('id', $message->id)
                            ->update([
                                'status' => 'failed',
                                'reason_for_failure' => $e->getMessage(),
                            ]);

                        Log::error('Notification failed', [
                            'id' => $message->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });
    }

    public function failed(Throwable $exception): void
    {
        Log::critical('SendQueuedNotificationsAndMessages job failed completely', [
            'error' => $exception->getMessage(),
        ]);
    }
}
