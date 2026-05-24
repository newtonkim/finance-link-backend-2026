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
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'topup_repayment_basis')) {
                $table->enum('topup_repayment_basis', ['principal', 'principal_interest', 'outstanding_balance'])
                    ->default('principal_interest')
                    ->after('allow_top_up');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'topup_min_percentage')) {
                $table->decimal('topup_min_percentage', 5, 2)->default(40.00)->after('topup_repayment_basis');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'topup_auto_disbursement')) {
                $table->boolean('topup_auto_disbursement')->default(false)->after('topup_min_percentage');
            }
        });

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'topup_repayment_basis')) {
                $table->enum('topup_repayment_basis', ['principal', 'principal_interest', 'outstanding_balance'])
                    ->nullable()
                    ->after('allow_top_up');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'topup_min_percentage')) {
                $table->decimal('topup_min_percentage', 5, 2)->nullable()->after('topup_repayment_basis');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'topup_auto_disbursement')) {
                $table->boolean('topup_auto_disbursement')->nullable()->after('topup_min_percentage');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            $table->dropColumn(['topup_repayment_basis', 'topup_min_percentage', 'topup_auto_disbursement']);
        });

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $table->dropColumn(['topup_repayment_basis', 'topup_min_percentage', 'topup_auto_disbursement']);
        });
    }
};
