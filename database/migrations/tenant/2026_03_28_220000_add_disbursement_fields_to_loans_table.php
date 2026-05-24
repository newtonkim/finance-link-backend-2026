<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            // loan_application_id and loan_officer_id already exist in legacy schema — skip
            $table->unsignedBigInteger('disbursed_by')->nullable()->after('approved_by');
            $table->string('disbursement_method', 50)->nullable()->after('disbursed_at');
            $table->string('disbursement_reference', 100)->nullable()->after('disbursement_method');
            $table->decimal('processing_fee', 15, 2)->default(0)->after('principal');
            $table->decimal('net_disbursed_amount', 15, 2)->nullable()->after('processing_fee');
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loans', function (Blueprint $table) {
            $table->dropColumn([
                'disbursed_by', 'disbursement_method', 'disbursement_reference',
                'processing_fee', 'net_disbursed_amount', 'notes',
            ]);
        });
    }
};
