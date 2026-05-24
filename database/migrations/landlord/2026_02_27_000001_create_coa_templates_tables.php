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
        Schema::connection('master')->create('coa_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('template_type')->unique(); // e.g. 'SACCO_UGANDA', 'MFI_TANZANIA'
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::connection('master')->create('coa_template_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('template_id')->constrained('coa_templates')->cascadeOnDelete();
            $table->string('gl_code');
            $table->string('name');
            $table->enum('account_type', ['ASSET', 'LIABILITY', 'EQUITY', 'INCOME', 'EXPENSE']);
            $table->string('account_subtype')->nullable();
            $table->enum('normal_balance', ['DR', 'CR']);
            $table->smallInteger('level');
            $table->uuid('parent_template_id')->nullable();
            $table->boolean('is_control')->default(false);
            $table->boolean('is_postable')->default(true);
            $table->boolean('allow_manual')->default(true);
            $table->string('ifrs_category')->nullable();
            $table->timestamps();

            $table->foreign('parent_template_id')->references('id')->on('coa_template_accounts')->nullOnDelete();
            $table->unique(['template_id', 'gl_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('master')->dropIfExists('coa_template_accounts');
        Schema::connection('master')->dropIfExists('coa_templates');
    }
};
