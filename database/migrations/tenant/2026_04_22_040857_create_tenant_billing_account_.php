<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('tenant_billing_account')) {
            return;
        }

        Schema::create('tenant_billing_account', function (Blueprint $table) {

            $table->id();

            $table->string('code', 100)->unique()->index();
            $table->string('name', 100)->unique()->index()->comment('e.g. SMS, Email');
            $table->decimal('account_balance', 10, 2)->default(0)->comment('cost per unit');
            $table->string('channel', 20)->index()->nullable()->comment('e.g. sms, email, whatsapp, mms');

            $table->enum('system_type', ['system', 'user_created'])
                ->default('system')
                ->index();

            // Audit
            $table->bigInteger('created_by')->nullable()->index();
            $table->bigInteger('updated_by')->nullable()->index();

            // Timestamps + soft delete
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->softDeletes();
        });
        DB::table('tenant_billing_account')->insert([
            [
                'code' => 'SMS',
                'name' => 'SMS',
                'channel' => 'sms',
                'account_balance' => 40000, // default account balance
                'system_type' => 'system',
            ],
            [
                'code' => 'EMAIL',
                'name' => 'EMAIL',
                'channel' => null,
                'account_balance' => 0,
                'system_type' => 'system',
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_billing_account');
    }
};
