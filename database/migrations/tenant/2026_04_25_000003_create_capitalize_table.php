<?php

use App\Http\Globals\GlobalHelpers;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('share_capitalization')) {
            return;
        }
        Schema::connection('tenant')->create('share_capitalization', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('open_capital')->comment('Opening capital are shared no /points')->index();
            $table->unsignedBigInteger('open_capital_points_walth_amount')->comment(' calculated amount of money of points open to be bought')->index();
            $table->unsignedBigInteger('opening_balance')->comment('Opening capital are shared no /points remaining after bought')->index();
            $table->decimal('share_price')->nullable()->index();
            $table->string('code')->nullable()->unique()->index();
            $table->enum('status', ['active', 'inactive', 'suspended'])
                ->default('active')
                ->index();

            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable()->index();

            $table->foreign('updated_by')
                ->references('id')->on('staff')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent()->nullable()->index();
            $table->timestamp('deleted_at')->nullable();
            $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable();
        });

        // Trigger must be created after the table exists.
        $trigger = new GlobalHelpers;
        $trigger->strictTheTrigger('share_capitalization');
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('share_capitalization');
    }
};
