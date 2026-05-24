<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            // Add the new referred_by field (who recruited/brought the member)
            if (! Schema::connection('tenant')->hasColumn('members', 'referred_by')) {
                $table->unsignedBigInteger('referred_by')->nullable()->after('onboarded_by');
                $table->foreign('referred_by')->references('id')->on('staff')->nullOnDelete();
            }

            // Rename created_by → registered_by (who physically entered the data)
            $table->renameColumn('created_by', 'registered_by');

            // Drop the redundant onboarded_by column (was always same as created_by)
            $table->dropForeign(['onboarded_by']);
            $table->dropColumn('onboarded_by');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn('referred_by');

            $table->renameColumn('registered_by', 'created_by');

            $table->unsignedBigInteger('onboarded_by')->nullable();
            $table->foreign('onboarded_by')->references('id')->on('staff')->nullOnDelete();
        });
    }
};
