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
        if (! Schema::hasTable('permissions')) {

            Schema::create('permissions', function (Blueprint $table) {

                $table->id();
                $table->string('action')->unique()->comment("permissions name e.g., 'edit articles' name must be unique in the system (create-tenants)")->index();
                $table->string('parent_module')->index()->comment('central-tenants')->index();
                $table->string('description')->nullable()->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->timestamp('deleted_at')->nullable();
                $table->index(['parent_module', 'action']);
            });

        }
        // if (! Schema::connection('master')->hasTable('role_has_permissions')) {
        //     Schema::connection('master')->create('role_has_permissions', function (Blueprint $table) {
        //         $table->unsignedBigInteger('permission_id');
        //         $table->unsignedBigInteger('role_id');

        //         $table->foreign('permission_id')
        //             ->references('id')
        //             ->on('permissions')
        //             ->onDelete('cascade');

        //         $table->foreign('role_id')
        //             ->references('id')
        //             ->on('roles')
        //             ->onDelete('cascade');

        //         $table->primary(['permission_id', 'role_id'], 'role_has_permissions_permission_id_role_id_primary');
        //     });
        // }

        // if (! Schema::connection('master')->hasTable('model_has_permissions')) {
        //     Schema::connection('master')->create('model_has_permissions', function (Blueprint $table) {
        //         $table->unsignedBigInteger('permission_id');
        //         $table->string('model_type');
        //         $table->unsignedBigInteger('model_id');

        //         $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

        //         $table->foreign('permission_id')
        //             ->references('id')
        //             ->on('permissions')
        //             ->onDelete('cascade');

        //         $table->primary(['permission_id', 'model_id', 'model_type'],
        //             'model_has_permissions_permission_model_type_primary');
        //     });
        // }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::connection('master')->dropIfExists('model_has_permissions');
        // Schema::connection('master')->dropIfExists('role_has_permissions');
        Schema::connection('master')->dropIfExists('permissions');
    }
};
