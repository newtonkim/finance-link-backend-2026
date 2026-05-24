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
            $table->unsignedBigInteger('approved_by')->nullable()->after('onboarded_by')->index();
            $table->timestamp('approved_at')->nullable()->after('approved_by')->index();
            $table->unsignedBigInteger('rejected_by')->nullable()->after('approved_at')->index();
            $table->timestamp('rejected_at')->nullable()->after('rejected_by')->index();
            $table->string('rejection_reason')->nullable()->after('rejected_at')->index();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason']);
        });
    }
};
