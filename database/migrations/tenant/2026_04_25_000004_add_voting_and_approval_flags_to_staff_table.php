<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->boolean('can_vote_on_loans')->default(false)->after('status');
            $table->boolean('can_manage_branch')->default(false)->after('can_vote_on_loans');
            $table->boolean('can_finalise_loan')->default(false)->after('can_manage_branch');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('staff', function (Blueprint $table) {
            $table->dropColumn([
                'can_vote_on_loans',
                'can_manage_branch',
                'can_finalise_loan',
            ]);
        });
    }
};
