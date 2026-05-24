<?php

use App\Http\Globals\GlobalHelpers;
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
        if (! Schema::hasTable('share_transaction_charges')) {
            if (Schema::hasTable('share_capitalization')) {
                return;
            }
            Schema::create('share_transaction_charges', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->string('code')->nullable()->unique()->index();
                $table->unsignedBigInteger('share_capitalization_id')->nullable()->index();

                $table->enum('transaction_type', ['buying', 'selling', 'withdraw', 'transfer'])->index();
                $table->decimal('minimum_shares', 15, 2)->default(0)->index();
                $table->decimal('maximum_shares', 15, 2)->nullable()->index();
                $table->decimal('amount', 15, 2);
                $table->enum('status', ['active', 'inactive'])->default('active')->index();
                $table->enum('charge_type', ['percentage', 'fixed'])->index();
                $table->enum('system_type', ['system', 'user_created'])->default('system')->index();

                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->unsignedBigInteger('updated_by')->nullable()->index();
                $table->foreign('updated_by')->references('id')->on('staff')->nullOnDelete();

                $table->timestamp('created_at')->useCurrent()->nullable()->index();
                $table->timestamp('deleted_at')->nullable();
                $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate()->nullable();
            });
            $trigger = new GlobalHelpers;
            $trigger->strictTheTrigger('share_transaction_charges');
        }
    }
};
