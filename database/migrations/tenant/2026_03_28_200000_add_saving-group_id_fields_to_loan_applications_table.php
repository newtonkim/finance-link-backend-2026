<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        // Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
        //     $table->json('savings_group_ids')->nullable()->after('member_id');
        // });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('loan_applications', function (Blueprint $table) {
            $table->dropColumn(['savings_group_ids']);
        });
    }
};
