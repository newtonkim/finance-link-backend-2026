<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('message_notification_settings')) {
            Schema::create('message_notification_settings', function (Blueprint $table) {

                $table->id();

                $table->string('code', 100)->unique()->index();

                // Pricing config
                $table->decimal('cost', 10, 2)->default(0)->comment('cost per unit');
                $table->decimal('platform_cost', 10, 2)->default(0)->comment('cost per platform');
                $table->enum('channel', ['in_app', 'email', 'sms', 'push', 'whatsapp', 'mms'])
                    ->default('sms');
                // $table->string('per_unit')->nullable(); // e.g. per_sms, per_email
                $table->integer('length_min')->nullable()->index();
                $table->integer('length_max')->nullable()->index();
                // $table->json('length_range')->nullable()->comment('{min, max}');

                $table->enum('status', ['active', 'deactivated'])
                    ->default('active')
                    ->index();

                // Toggle
                $table->boolean('enabled')->default(true)->index();

                // System type
                $table->enum('system_type', ['system', 'user_created'])
                    ->default('user_created')
                    ->index();

                // Audit
                $table->bigInteger('created_by')->nullable()->index();
                $table->bigInteger('updated_by')->nullable()->index();

                // Timestamps + soft delete
                $table->timestamp('created_at')->useCurrent();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->softDeletes();
            });

            DB::table('message_notification_settings')->insert([
                [
                    'code' => 'SMS',
                    'cost' => 45,
                    // 'per_unit' => 'per_sms',
                    'length_min' => 1,
                    'length_max' => 160,
                    'status' => 'active',
                    'enabled' => true,
                    'system_type' => 'system',

                ],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('message_notification_settings');
    }
};
