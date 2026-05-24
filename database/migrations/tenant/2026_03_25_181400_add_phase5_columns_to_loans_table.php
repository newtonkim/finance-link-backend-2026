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
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('loans', 'assigned_after_decline_to_review')) {
                $table->unsignedBigInteger('assigned_after_decline_to_review')->nullable();
                $table->foreign('assigned_after_decline_to_review', 'loans_assigned_after_decline_fk')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'assigned_to_disburse')) {
                $table->unsignedBigInteger('assigned_to_disburse')->nullable();
                $table->foreign('assigned_to_disburse', 'loans_assigned_to_disburse_fk')->references('id')->on('staff')->nullOnDelete();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'comment_on_disburse')) {
                $table->text('comment_on_disburse')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'interest_method')) {
                $table->string('interest_method')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'interest_period')) {
                $table->string('interest_period')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'override_interest')) {
                $table->boolean('override_interest')->default(false);
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'overrride_interest_amount')) {
                $table->decimal('overrride_interest_amount', 15, 2)->default(0);
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_duration')) {
                $table->integer('loan_duration')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'loan_duration_type')) {
                $table->string('loan_duration_type')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'repayment_cycle')) {
                $table->string('repayment_cycle')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'description')) {
                $table->text('description')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('loans', 'balance')) {
                $table->decimal('balance', 15, 2)->default(0);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            if (Schema::connection('tenant')->hasColumn('loans', 'balance')) {
                $table->dropColumn('balance');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'description')) {
                $table->dropColumn('description');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'repayment_cycle')) {
                $table->dropColumn('repayment_cycle');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_duration_type')) {
                $table->dropColumn('loan_duration_type');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'loan_duration')) {
                $table->dropColumn('loan_duration');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'overrride_interest_amount')) {
                $table->dropColumn('overrride_interest_amount');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'override_interest')) {
                $table->dropColumn('override_interest');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'interest_period')) {
                $table->dropColumn('interest_period');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'interest_method')) {
                $table->dropColumn('interest_method');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'comment_on_disburse')) {
                $table->dropColumn('comment_on_disburse');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'assigned_to_disburse')) {
                $table->dropForeign('loans_assigned_to_disburse_fk');
                $table->dropColumn('assigned_to_disburse');
            }
            if (Schema::connection('tenant')->hasColumn('loans', 'assigned_after_decline_to_review')) {
                $table->dropForeign('loans_assigned_after_decline_fk');
                $table->dropColumn('assigned_after_decline_to_review');
            }
        });
    }
};
