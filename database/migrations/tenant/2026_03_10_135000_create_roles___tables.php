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

        if (Schema::connection('tenant')->hasTable('roles')) {
            return;
        }
        Schema::connection('tenant')->create('roles', function (Blueprint $table) {

            $table->id();
            $table->string('name')->comment("roles name e.g., 'admin', 'editor','super-admin', 'tenant-admin' ")->index();       // e.g., 'admin', 'editor'
            if (! Schema::hasColumn('roles', 'code')) {
                $table->string('code')->comment('code ')->index();
            }       // e.g., 'admin', 'editor'
            if (! Schema::hasColumn('roles', 'system_type')) {
                $table->string('system_type')->index();
            }       // e.g., 'admin', 'editor'
            $table->Json('default_permissions')->nullable();
            $table->string('description')->nullable()->index();
            // Controls how far a role can see across branches:
            //   'all'    — sees all branches (tenant admin)
            //   'branch' — sees own branch only
            //   'self'   — sees only own records (default / most restricted)
            $table->string('branch_scope')->default('self')->index();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->timestamp('deleted_at')->nullable();
            $table->unique(['name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::connection('master')->dropIfExists('model_has_roles');
        Schema::connection('master')->dropIfExists('roles');
    }
};
