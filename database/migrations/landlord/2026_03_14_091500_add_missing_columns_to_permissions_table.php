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
        if (Schema::connection('master')->hasTable('permissions')) {
            Schema::connection('master')->table('permissions', function (Blueprint $table) {
                if (! Schema::connection('master')->hasColumn('permissions', 'parent_module')) {
                    $table->string('parent_module')->index()->after('action')->comment('Module group e.g., dashboard, licenses');
                }
                if (! Schema::connection('master')->hasColumn('permissions', 'description')) {
                    $table->string('description')->nullable()->after('parent_module');
                }
                if (! Schema::connection('master')->hasColumn('permissions', 'created_at')) {
                    $table->timestamps();
                }
                if (! Schema::connection('master')->hasColumn('permissions', 'deleted_at')) {
                    $table->timestamp('deleted_at')->nullable();
                }
            });

            // Ensure composite index exists (ignore if already present)
            try {
                Schema::connection('master')->table('permissions', function (Blueprint $table) {
                    $table->index(['parent_module', 'action']);
                });
            } catch (Throwable $e) {
                // Index may already exist; ignore
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::connection('master')->hasTable('permissions')) {
            Schema::connection('master')->table('permissions', function (Blueprint $table) {
                if (Schema::connection('master')->hasColumn('permissions', 'deleted_at')) {
                    $table->dropColumn('deleted_at');
                }
                if (Schema::connection('master')->hasColumn('permissions', 'description')) {
                    $table->dropColumn('description');
                }
                if (Schema::connection('master')->hasColumn('permissions', 'parent_module')) {
                    // Drop index if present then column
                    try {
                        $table->dropIndex(['parent_module', 'action']);
                    } catch (Throwable $e) {
                    }
                    $table->dropColumn('parent_module');
                }
                // Do not drop timestamps if they previously existed; keep conservative
            });
        }
    }
};
