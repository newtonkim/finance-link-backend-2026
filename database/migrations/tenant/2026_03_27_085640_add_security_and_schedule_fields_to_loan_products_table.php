<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->decimal('savings_appraisal_threshold', 5, 2)->default(0)->after('grace_period')
                ->comment('% of applicant savings that does not require appraisal');
            $table->unsignedSmallInteger('warning_days')->nullable()->after('savings_appraisal_threshold')
                ->comment('Days before due date to start sending repayment reminders');
            $table->unsignedTinyInteger('max_securities')->default(3)->after('warning_days')
                ->comment('Maximum number of securities allowed for a loan');
            $table->decimal('security_value_percentage', 5, 2)->default(150)->after('max_securities')
                ->comment('Required security value as % of loan amount');
            $table->boolean('allow_sub_schedule')->default(false)->after('security_value_percentage')
                ->comment('Allow reducing balance interest generation on a yearly basis');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn([
                'savings_appraisal_threshold',
                'warning_days',
                'max_securities',
                'security_value_percentage',
                'allow_sub_schedule',
            ]);
        });
    }
};
