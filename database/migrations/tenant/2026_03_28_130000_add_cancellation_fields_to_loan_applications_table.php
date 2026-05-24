<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('rejection_reason');
            $table->timestamp('cancelled_at')->nullable()->after('disbursed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancellation_reason', 'cancelled_at', 'cancelled_by']);
        });
    }
};
