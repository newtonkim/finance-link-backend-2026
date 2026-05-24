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
            if (! Schema::connection('tenant')->hasColumn('members', 'joined_date')) {
                $table->timestamp('joined_date')->nullable()->after('referred_by');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            $table->dropColumn([
                'joined_date',
            ]);
        });
    }
};
