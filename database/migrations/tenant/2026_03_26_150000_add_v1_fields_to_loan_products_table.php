<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'code')) {
                $table->string('code')->nullable()->after('id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'repayment_structure')) {
                $table->string('repayment_structure')->nullable()->after('interest_method');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'requires_approval')) {
                $table->boolean('requires_approval')->default(false)->after('penalty_type');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'allow_top_up')) {
                $table->boolean('allow_top_up')->default(true)->after('requires_approval');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'allow_reschedule')) {
                $table->boolean('allow_reschedule')->default(true)->after('allow_top_up');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'processing_fee_type')) {
                $table->string('processing_fee_type')->default('none')->after('allow_reschedule');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'processing_fee_value')) {
                $table->decimal('processing_fee_value', 15, 2)->default(0)->after('processing_fee_type');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'loan_portfolio_account_id')) {
                $table->unsignedBigInteger('loan_portfolio_account_id')->nullable()->after('processing_fee_value');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'interest_income_account_id')) {
                $table->unsignedBigInteger('interest_income_account_id')->nullable()->after('loan_portfolio_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'interest_receivable_account_id')) {
                $table->unsignedBigInteger('interest_receivable_account_id')->nullable()->after('interest_income_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'penalty_income_account_id')) {
                $table->unsignedBigInteger('penalty_income_account_id')->nullable()->after('interest_receivable_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'penalty_receivable_account_id')) {
                $table->unsignedBigInteger('penalty_receivable_account_id')->nullable()->after('penalty_income_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'disbursement_account_id')) {
                $table->unsignedBigInteger('disbursement_account_id')->nullable()->after('penalty_receivable_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'created_by')) {
                $table->unsignedBigInteger('created_by')->nullable()->after('disbursement_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'updated_by')) {
                $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $columns = [
                'code',
                'description',
                'repayment_structure',
                'requires_approval',
                'allow_top_up',
                'allow_reschedule',
                'processing_fee_type',
                'processing_fee_value',
                'loan_portfolio_account_id',
                'interest_income_account_id',
                'interest_receivable_account_id',
                'penalty_income_account_id',
                'penalty_receivable_account_id',
                'disbursement_account_id',
                'created_by',
                'updated_by',
            ];

            foreach ($columns as $column) {
                if (Schema::connection('tenant')->hasColumn('loan_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
