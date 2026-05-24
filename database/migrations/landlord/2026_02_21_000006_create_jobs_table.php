<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Each Schema::create is guarded so this migration is idempotent. In test
     * environments the same DB hosts both landlord and tenant schemas, and the
     * tenant schema dump (database/schema/tenant-schema.sql) already pre-creates
     * jobs / job_batches / failed_jobs. Without these guards the landlord copy
     * collides with the dump: `1050 Table 'jobs' already exists`. The other
     * landlord framework migrations (auth_support, cache, personal_access_tokens)
     * already follow this pattern.
     */
    public function up(): void
    {
        if (! Schema::connection('master')->hasTable('jobs')) {
            Schema::connection('master')->create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });
        }

        if (! Schema::connection('master')->hasTable('job_batches')) {
            Schema::connection('master')->create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }

        if (! Schema::connection('master')->hasTable('failed_jobs')) {
            Schema::connection('master')->create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('master')->dropIfExists('jobs');
        Schema::connection('master')->dropIfExists('job_batches');
        Schema::connection('master')->dropIfExists('failed_jobs');
    }
};
