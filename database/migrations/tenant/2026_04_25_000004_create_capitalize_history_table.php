<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('share_capitalization_history')) {
            return;
        }
        Schema::connection('tenant')->create('share_capitalization_history', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique()->index();
            $table->enum('status', ['active', 'inactive', 'suspended'])
                ->default('active')
                ->index();
            $table->unsignedBigInteger('share_capitalization_id')->nullable()->comment('share_capitalization table id ')->index();
            $table->unsignedBigInteger('open_capital')->comment('Opening capital are shared no /points')->index();
            $table->unsignedBigInteger('open_capital_points_walth_amount')->comment(' calculated amount of money of points open to be bought')->index('sch_ocpwa_idx');
            $table->unsignedBigInteger('opening_balance')->comment('Opening capital are shared no /points remaining after bought')->index();
            $table->decimal('share_price')->nullable()->index();

            $table->unsignedBigInteger('new_open_capital')->comment('')->index();
            $table->unsignedBigInteger('new_open_capital_points_walth_amount')->comment(' ')->index('sch_nocpwa_idx');
            $table->unsignedBigInteger('new_opening_balance')->comment('')->index();
            $table->decimal('new_share_price')->nullable()->index();
            $table->json('other_data')->nullable();

            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            $table->foreign('updated_by')
                ->references('id')->on('staff')->nullOnDelete();

            $table->foreign('share_capitalization_id')
                ->references('id')->on('share_capitalization')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent()->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->enum('system_type', ['system', 'user_created'])->default('system')->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('share_capitalization_history');
    }
};
