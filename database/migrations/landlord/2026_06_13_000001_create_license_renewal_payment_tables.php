<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('master')->hasTable('license_invoices')) {
            Schema::connection('master')->create('license_invoices', function (Blueprint $table) {
                $table->id();
                $table->uuid('license_id')->index();
                $table->uuid('tenant_id')->index();
                $table->unsignedBigInteger('plan_id')->nullable()->index();
                $table->string('invoice_number')->unique();
                $table->string('currency', 12)->default('UGX');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('service_fee', 14, 2)->default(0);
                $table->decimal('total', 14, 2)->default(0);
                $table->string('status')->default('pending')->index();
                $table->date('due_at')->nullable()->index();
                $table->timestamp('paid_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::connection('master')->hasTable('license_payments')) {
            Schema::connection('master')->create('license_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('license_invoice_id')->index();
                $table->uuid('tenant_id')->index();
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 12)->default('UGX');
                $table->string('payment_method')->index();
                $table->string('provider')->nullable()->index();
                $table->string('phone_number')->nullable();
                $table->string('account_name')->nullable();
                $table->boolean('save_payment_method')->default(false);
                $table->string('status')->default('pending')->index();
                $table->timestamp('paid_at')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('license_payments');
        Schema::connection('master')->dropIfExists('license_invoices');
    }
};
