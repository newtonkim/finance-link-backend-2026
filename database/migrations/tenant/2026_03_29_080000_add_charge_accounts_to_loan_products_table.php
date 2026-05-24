<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loan_products', 'charges_income_account_id')) {
                $table->unsignedBigInteger('charges_income_account_id')->nullable()->after('disbursement_account_id');
            }

            if (! Schema::connection('tenant')->hasColumn('loan_products', 'charges_receivable_account_id')) {
                $table->unsignedBigInteger('charges_receivable_account_id')->nullable()->after('charges_income_account_id');
            }
        });

        // Add foreign key constraints
        $foreignMap = [
            'charges_income_account_id' => 'loan_products_charges_income_account_id_foreign',
            'charges_receivable_account_id' => 'loan_products_charges_receivable_account_id_foreign',
        ];

        foreach ($foreignMap as $column => $constraint) {
            if (
                Schema::connection('tenant')->hasColumn('loan_products', $column)
                && Schema::connection('tenant')->hasTable('chart_of_accounts')
                && ! $this->constraintExists($constraint)
            ) {
                Schema::connection('tenant')->table('loan_products', function (Blueprint $table) use ($column, $constraint) {
                    $table->foreign($column, $constraint)->references('id')->on('chart_of_accounts')->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            foreach ([
                'loan_products_charges_income_account_id_foreign',
                'loan_products_charges_receivable_account_id_foreign',
            ] as $constraint) {
                try {
                    $table->dropForeign($constraint);
                } catch (Throwable $e) {
                }
            }

            foreach (['charges_income_account_id', 'charges_receivable_account_id'] as $column) {
                if (Schema::connection('tenant')->hasColumn('loan_products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function constraintExists(string $constraintName): bool
    {
        return DB::connection('tenant')->table('information_schema.table_constraints')
            ->where('table_schema', DB::connection('tenant')->getDatabaseName())
            ->where('table_name', 'loan_products')
            ->where('constraint_name', $constraintName)
            ->exists();
    }
};
