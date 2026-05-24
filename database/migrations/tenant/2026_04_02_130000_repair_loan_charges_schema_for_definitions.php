<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = 'tenant';

        $hasLoanCharges = Schema::connection($connection)->hasTable('loan_charges');
        $hasAppliedCharges = Schema::connection($connection)->hasTable('loan_applied_charges');

        if ($hasLoanCharges) {
            $hasCategory = Schema::connection($connection)->hasColumn('loan_charges', 'category');
            $looksLikeLegacyAppliedTable =
                Schema::connection($connection)->hasColumn('loan_charges', 'loan_id') &&
                Schema::connection($connection)->hasColumn('loan_charges', 'charge_id');

            if (! $hasCategory && $looksLikeLegacyAppliedTable && ! $hasAppliedCharges) {
                Schema::connection($connection)->rename('loan_charges', 'loan_applied_charges');
                $hasLoanCharges = false;
            }
        }

        if (! $hasLoanCharges) {
            Schema::connection($connection)->create('loan_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->nullable();
                $table->string('name');
                $table->string('code')->nullable();
                $table->enum('category', ['processing_fee', 'penalty', 'late_fee', 'appraisal_fee', 'insurance', 'other']);
                $table->enum('charge_type', ['flat', 'percentage']);
                $table->decimal('value', 15, 2)->default(0);
                $table->enum('frequency', ['one_time', 'daily', 'weekly', 'monthly'])->default('one_time');
                $table->unsignedInteger('grace_days')->default(0);
                $table->decimal('max_value', 15, 2)->nullable();
                $table->enum('max_value_type', ['none', 'flat_cap', 'percentage_of_outstanding'])->default('none');
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('income_account_id')->nullable();
                $table->unsignedBigInteger('receivable_account_id')->nullable();
                $table->text('description')->nullable();
                $table->timestamps();

                $table->index('tenant_id');
                $table->index('category');
                $table->index('is_active');
                $table->index('code');

                $table->foreign('income_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
                $table->foreign('receivable_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
            });

            return;
        }

        Schema::connection($connection)->table('loan_charges', function (Blueprint $table) use ($connection) {
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'category')) {
                $table->enum('category', ['processing_fee', 'penalty', 'late_fee', 'appraisal_fee', 'insurance', 'other'])->default('other')->after('code');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'value')) {
                $table->decimal('value', 15, 2)->default(0)->after('charge_type');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'frequency')) {
                $table->enum('frequency', ['one_time', 'daily', 'weekly', 'monthly'])->default('one_time')->after('value');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'grace_days')) {
                $table->unsignedInteger('grace_days')->default(0)->after('frequency');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'max_value')) {
                $table->decimal('max_value', 15, 2)->nullable()->after('grace_days');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'max_value_type')) {
                $table->enum('max_value_type', ['none', 'flat_cap', 'percentage_of_outstanding'])->default('none')->after('max_value');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('max_value_type');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'income_account_id')) {
                $table->unsignedBigInteger('income_account_id')->nullable()->after('is_active');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'receivable_account_id')) {
                $table->unsignedBigInteger('receivable_account_id')->nullable()->after('income_account_id');
            }
            if (! Schema::connection($connection)->hasColumn('loan_charges', 'description')) {
                $table->text('description')->nullable()->after('receivable_account_id');
            }
        });
    }

    public function down(): void
    {
        // Intentionally left blank: this migration repairs divergent tenant schemas.
    }
};
