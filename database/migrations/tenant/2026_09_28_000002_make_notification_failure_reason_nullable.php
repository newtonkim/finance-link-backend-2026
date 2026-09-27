<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * reason_for_failure is only filled when a message fails, but was created NOT NULL
 * with no default. Under strict mode that made every message that did not fail
 * impossible to queue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sent_message_notifications', function (Blueprint $table) {
            $table->string('reason_for_failure', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('sent_message_notifications', function (Blueprint $table) {
            $table->string('reason_for_failure', 200)->nullable(false)->default('')->change();
        });
    }
};
