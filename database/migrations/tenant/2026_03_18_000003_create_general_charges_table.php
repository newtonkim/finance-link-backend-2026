<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('general_charges')) {
            return;
        }

        Schema::connection('tenant')->create('general_charges', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->boolean('is_revenue')->default(true)->index();
            $table->string('application')->index(); // on_shares, on_registration, on_loan_application, other
            $table->string('where_to_apply')->nullable()->index(); // loans, savings, shares (for 'other')
            $table->boolean('is_fine')->default(false)->index();
            $table->string('charge_type')->nullable()->index(); // percentage, amount
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('interval_type')->nullable()->index(); // days, weeks, months, years
            $table->integer('interval')->nullable()->index();
            $table->unsignedBigInteger('credit_account_id')->nullable()->index(); // COA account
            $table->json('saving_product_ids')->nullable();
            $table->json('loan_product_ids')->nullable();
            $table->boolean('is_reversible')->default(true)->index();
            $table->timestamp('created_at')->nullable()->useCurrent()->index();
            $table->timestamp('updated_at')->nullable()->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('general_charges');
    }
};
