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

        $foreignMap = [
            'loan_portfolio_account_id' => ['chart_of_accounts', 'loan_products_loan_portfolio_account_id_foreign'],
            'interest_income_account_id' => ['chart_of_accounts', 'loan_products_interest_income_account_id_foreign'],
            'interest_receivable_account_id' => ['chart_of_accounts', 'loan_products_interest_receivable_account_id_foreign'],
            'penalty_income_account_id' => ['chart_of_accounts', 'loan_products_penalty_income_account_id_foreign'],
            'penalty_receivable_account_id' => ['chart_of_accounts', 'loan_products_penalty_receivable_account_id_foreign'],
            'disbursement_account_id' => ['chart_of_accounts', 'loan_products_disbursement_account_id_foreign'],
            'created_by' => ['staff', 'loan_products_created_by_foreign'],
            'updated_by' => ['staff', 'loan_products_updated_by_foreign'],
        ];

        foreach ($foreignMap as $column => [$targetTable, $constraint]) {
            if (
                Schema::connection('tenant')->hasColumn('loan_products', $column)
                && Schema::connection('tenant')->hasTable($targetTable)
                && ! $this->constraintExists($constraint)
            ) {
                Schema::connection('tenant')->table('loan_products', function (Blueprint $table) use ($column, $targetTable, $constraint) {
                    $table->foreign($column, $constraint)->references('id')->on($targetTable)->nullOnDelete();
                });
            }
        }

        if (Schema::connection('tenant')->hasColumn('loan_products', 'code') && ! $this->indexExists('loan_products_code_unique')) {
            Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
                $table->unique('code', 'loan_products_code_unique');
            });
        }

        if (Schema::connection('tenant')->hasColumn('loan_products', 'interest_method') && ! $this->indexExists('loan_products_interest_method_index')) {
            Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
                $table->index('interest_method', 'loan_products_interest_method_index');
            });
        }

        if (Schema::connection('tenant')->hasColumn('loan_products', 'repayment_structure') && ! $this->indexExists('loan_products_repayment_structure_index')) {
            Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
                $table->index('repayment_structure', 'loan_products_repayment_structure_index');
            });
        }

        if (
            Schema::connection('tenant')->hasColumn('loan_products', 'branch_id')
            && ! $this->indexExists('loan_products_branch_id_index')
            && ! $this->columnHasAnyIndex('branch_id')
        ) {
            Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
                $table->index('branch_id', 'loan_products_branch_id_index');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::connection('tenant')->hasTable('loan_products')) {
            return;
        }

        Schema::connection('tenant')->table('loan_products', function (Blueprint $table) {
            $constraints = [
                'loan_products_loan_portfolio_account_id_foreign',
                'loan_products_interest_income_account_id_foreign',
                'loan_products_interest_receivable_account_id_foreign',
                'loan_products_penalty_income_account_id_foreign',
                'loan_products_penalty_receivable_account_id_foreign',
                'loan_products_disbursement_account_id_foreign',
                'loan_products_created_by_foreign',
                'loan_products_updated_by_foreign',
            ];

            foreach ($constraints as $constraint) {
                try {
                    $table->dropForeign($constraint);
                } catch (Throwable $e) {
                }
            }

            foreach ([
                'loan_products_code_unique',
                'loan_products_interest_method_index',
                'loan_products_repayment_structure_index',
                'loan_products_branch_id_index',
            ] as $index) {
                try {
                    $table->dropIndex($index);
                } catch (Throwable $e) {
                }
            }
        });
    }

    private function indexExists(string $indexName): bool
    {
        return Schema::connection('tenant')->hasIndex('loan_products', $indexName);
    }

    private function columnHasAnyIndex(string $columnName): bool
    {
        $indexes = Schema::connection('tenant')->getIndexes('loan_products');
        foreach ($indexes as $index) {
            if (in_array($columnName, $index['columns'], true)) {
                return true;
            }
        }

        return false;
    }

    private function constraintExists(string $constraintName): bool
    {
        $foreignKeys = Schema::connection('tenant')->getForeignKeys('loan_products');
        foreach ($foreignKeys as $fk) {
            if ($fk['name'] === $constraintName) {
                return true;
            }
        }

        return false;
    }
};
