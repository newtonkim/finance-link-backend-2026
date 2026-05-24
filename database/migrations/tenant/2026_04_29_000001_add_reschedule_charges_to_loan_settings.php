<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            $afterAnchor = Schema::connection('tenant')->hasColumn('loan_settings', 'topup_auto_disbursement')
                ? 'topup_auto_disbursement'
                : 'allow_top_up';

            // Shared GL income account for all reschedule fees
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_income_account_id')) {
                $table->unsignedBigInteger('reschedule_fee_income_account_id')->nullable()->after($afterAnchor);
            }

            // 1. Reschedule Fee (every reschedule)
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_enabled')) {
                $table->boolean('reschedule_fee_enabled')->default(false);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_type')) {
                $table->string('reschedule_fee_type', 20)->default('flat');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_amount')) {
                $table->decimal('reschedule_fee_amount', 15, 4)->default(0);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_basis')) {
                $table->string('reschedule_fee_basis', 30)->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_fee_collection')) {
                $table->string('reschedule_fee_collection', 20)->default('cash');
            }

            // 2. Product Change Fee
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_product_change_fee_enabled')) {
                $table->boolean('reschedule_product_change_fee_enabled')->default(false);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_product_change_fee_type')) {
                $table->string('reschedule_product_change_fee_type', 20)->default('flat');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_product_change_fee_amount')) {
                $table->decimal('reschedule_product_change_fee_amount', 15, 4)->default(0);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_product_change_fee_basis')) {
                $table->string('reschedule_product_change_fee_basis', 30)->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_product_change_fee_collection')) {
                $table->string('reschedule_product_change_fee_collection', 20)->default('cash');
            }

            // 3. Same Product Fee
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_same_product_fee_enabled')) {
                $table->boolean('reschedule_same_product_fee_enabled')->default(false);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_same_product_fee_type')) {
                $table->string('reschedule_same_product_fee_type', 20)->default('flat');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_same_product_fee_amount')) {
                $table->decimal('reschedule_same_product_fee_amount', 15, 4)->default(0);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_same_product_fee_basis')) {
                $table->string('reschedule_same_product_fee_basis', 30)->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_same_product_fee_collection')) {
                $table->string('reschedule_same_product_fee_collection', 20)->default('cash');
            }

            // 4. Other Charges (admin-armed)
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_other_charges_enabled')) {
                $table->boolean('reschedule_other_charges_enabled')->default(false);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_other_charges_type')) {
                $table->string('reschedule_other_charges_type', 20)->default('flat');
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_other_charges_amount')) {
                $table->decimal('reschedule_other_charges_amount', 15, 4)->default(0);
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_other_charges_basis')) {
                $table->string('reschedule_other_charges_basis', 30)->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loan_settings', 'reschedule_other_charges_collection')) {
                $table->string('reschedule_other_charges_collection', 20)->default('cash');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_settings', function (Blueprint $table) {
            $columns = [
                'reschedule_fee_income_account_id',
                'reschedule_fee_enabled', 'reschedule_fee_type', 'reschedule_fee_amount',
                'reschedule_fee_basis', 'reschedule_fee_collection',
                'reschedule_product_change_fee_enabled', 'reschedule_product_change_fee_type',
                'reschedule_product_change_fee_amount', 'reschedule_product_change_fee_basis',
                'reschedule_product_change_fee_collection',
                'reschedule_same_product_fee_enabled', 'reschedule_same_product_fee_type',
                'reschedule_same_product_fee_amount', 'reschedule_same_product_fee_basis',
                'reschedule_same_product_fee_collection',
                'reschedule_other_charges_enabled', 'reschedule_other_charges_type',
                'reschedule_other_charges_amount', 'reschedule_other_charges_basis',
                'reschedule_other_charges_collection',
            ];
            $existing = array_filter($columns, fn ($col) => Schema::connection('tenant')->hasColumn('loan_settings', $col));
            if (! empty($existing)) {
                $table->dropColumn(array_values($existing));
            }
        });
    }
};
