<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! Schema::connection('tenant')->hasColumn('loan_applications', 'risk_rating')) {
            Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
                $table->enum('risk_rating', ['low', 'medium', 'high', 'critical'])->nullable()->after('appraisal_notes')->index();
                $table->timestamp('reviewed_at')->nullable()->after('submitted_at')->index();
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at')->index();
                $table->text('return_reason')->nullable()->after('appraisal_notes');
                $table->timestamp('returned_at')->nullable()->after('reviewed_at')->index();
                $table->unsignedBigInteger('returned_by')->nullable()->after('returned_at')->index();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->dropColumn([
                'risk_rating',
                'reviewed_at',
                'reviewed_by',
                'return_reason',
                'returned_at',
                'returned_by',
            ]);
        });
    }
};
