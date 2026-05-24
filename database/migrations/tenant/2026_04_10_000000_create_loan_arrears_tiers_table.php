<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('loan_arrears_tiers')) {
            return;
        }

        Schema::create('loan_arrears_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            // Defines the day range [from_day, to_day]. to_day can be null for 'and above'
            $table->integer('from_day')->unsigned();
            $table->integer('to_day')->unsigned()->nullable();

            // Type of penalty: flat or percentage
            $table->string('charge_type', 50)->default('percentage');
            // The value to charge (amount or rate)
            $table->decimal('charge_value', 15, 2)->default(0);

            // Base amount to apply to: outstanding_balance, principal_due, installment_due
            $table->string('applies_to', 50)->default('outstanding_balance');

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            // Ensure no overlapping or duplicate tiers can easily happen (though logic handles actual overlap validation)
            $table->unique(['tenant_id', 'from_day', 'to_day'], 'tenant_tier_unique');
        });

        Schema::table('loan_repayment_schedule', function (Blueprint $table) {
            $table->unsignedBigInteger('last_arrears_tier_id')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('loan_repayment_schedule', function (Blueprint $table) {
            $table->dropColumn('last_arrears_tier_id');
        });
        Schema::dropIfExists('loan_arrears_tiers');
    }
};
