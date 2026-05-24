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
        if (! Schema::connection('master')->hasTable('roles')) {
            Schema::connection('master')->create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name')->comment("roles name e.g., 'admin', 'editor','super-admin', 'tenant-admin' ")->index();       // e.g., 'admin', 'editor'
                $table->Json('default_permissions')->nullable("json[1,2,3] default permission ids  that will be assigned to the role on every user's creation");
                $table->string('description')->nullable()->index();
                $table->timestamp('created_at')->useCurrent()->index();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
                $table->timestamp('deleted_at')->nullable();
                $table->unique(['name']);
            });
        }

        // if (! Schema::connection('master')->hasTable('model_has_roles')) {
        //     Schema::connection('master')->create('model_has_roles', function (Blueprint $table) {
        //         $table->unsignedBigInteger('role_id');
        //         $table->string('model_type');
        //         $table->unsignedBigInteger('model_id');

        //         $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

        //         $table->foreign('role_id')
        //             ->references('id')
        //             ->on('roles')
        //             ->onDelete('cascade');

        //         $table->primary(['role_id', 'model_id', 'model_type'],
        //             'model_has_roles_role_model_type_primary');
        //     });
        // }
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
