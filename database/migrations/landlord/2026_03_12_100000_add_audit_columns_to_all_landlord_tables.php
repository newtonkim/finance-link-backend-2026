<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'master';

    /**
     * All landlord business tables that should carry audit columns.
     * Infrastructure tables (sessions, cache, jobs, etc.) are intentionally excluded.
     */
    protected array $tables = [
        'tenants',
        'platform_users',
        'licenses',
        'plans',
        'coa_templates',
        'coa_template_accounts',
        'roles',
        'permissions',
        'model_has_roles',
        'role_has_permissions',
        'model_has_permissions',
        'permissions_users',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection('master')->hasTable($table)) {
                continue;
            }

            Schema::connection('master')->table($table, function (Blueprint $t) use ($table) {
                if (! Schema::connection('master')->hasColumn($table, 'created_by')) {
                    $t->unsignedBigInteger('created_by')->nullable();
                }

                if (! Schema::connection('master')->hasColumn($table, 'updated_by')) {
                    $t->unsignedBigInteger('updated_by')->nullable();
                }

                if (! Schema::connection('master')->hasColumn($table, 'deleted_by')) {
                    $t->unsignedBigInteger('deleted_by')->nullable();
                }

                if (! Schema::connection('master')->hasColumn($table, 'system_type')) {
                    $t->enum('system_type', ['system', 'user_created'])->nullable()->default('user_created');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::connection('master')->hasTable($table)) {
                continue;
            }

            Schema::connection('master')->table($table, function (Blueprint $t) use ($table) {
                $toDrop = [];

                foreach (['created_by', 'updated_by', 'deleted_by', 'system_type'] as $column) {
                    if (Schema::connection('master')->hasColumn($table, $column)) {
                        $toDrop[] = $column;
                    }
                }

                if ($toDrop) {
                    $t->dropColumn($toDrop);
                }
            });
        }
    }
};
