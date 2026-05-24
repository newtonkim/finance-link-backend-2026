<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('share_capitalization', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('share_capitalization', 'deleted_at')) {
                $table->timestamp('deleted_at')->nullable();
            }
            if (! Schema::connection('tenant')->hasColumn('share_capitalization', 'system_type')) {
                $table->enum('system_type', ['system', 'user_created'])->default('user_created')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('share_capitalization', function (Blueprint $table) {
            $table->dropColumn([
                'system_type',
                'deleted_at',
            ]);
        });
    }
};
