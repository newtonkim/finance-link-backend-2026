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
        Schema::table('chart_of_accounts', function (Blueprint $table) {

            if (Schema::hasColumn('chart_of_accounts', 'code')) {
                $table->renameColumn('code', 'gl_code');
            }

            // Rename 'type' to 'account_type' to match the ENUM requirement
            if (Schema::hasColumn('chart_of_accounts', 'type')) {
                $table->renameColumn('type', 'account_type_legacy');
            }

            if (!Schema::hasColumn('chart_of_accounts', 'account_subtype')) {
                $table->string('account_subtype')->nullable()->after('name');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'account_type')) {
                $table->enum('account_type', ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE'])->after('account_subtype');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'normal_balance')) {
                $table->enum('normal_balance', ['DR', 'CR'])->after('account_type');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'level')) {
                $table->smallInteger('level')->default(1)->after('normal_balance');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'is_control')) {
                $table->boolean('is_control')->default(false)->after('parent_id');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'is_postable')) {
                $table->boolean('is_postable')->default(true)->after('is_control');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'allow_manual')) {
                $table->boolean('allow_manual')->default(true)->after('is_active');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'ifrs_category')) {
                $table->string('ifrs_category')->nullable()->after('allow_manual');
            }
            if (!Schema::hasColumn('chart_of_accounts', 'sort_order')) {
                $table->integer('sort_order')->nullable()->after('ifrs_category');
            }

            // Unique constraint for tenant (multi-database means tenant_id is implicit, but let's add it if needed)
            // Existing table has 'id' as bigint, let's keep it.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'account_subtype',
                'account_type',
                'normal_balance',
                'level',
                'is_control',
                'is_postable',
                'allow_manual',
                'ifrs_category',
                'sort_order',
            ]);

            if (Schema::hasColumn('chart_of_accounts', 'gl_code')) {
                $table->renameColumn('gl_code', 'code');
            }

            if (Schema::hasColumn('chart_of_accounts', 'account_type_legacy')) {
                $table->renameColumn('account_type_legacy', 'type');
            }
        });
    }
};
