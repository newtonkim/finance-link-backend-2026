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
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            // Ensure member_type exists or update its constraints if needed.
            // Based on Member model, member_type is already fillable, let's ensure the column is there and has a default.
            if (! Schema::connection('tenant')->hasColumn('members', 'member_type')) {
                $table->string('member_type')->default('full')->after('member_number')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('tenant')->table('members', function (Blueprint $table) {
            // No strict need to drop in this context if we are just ensuring it exists,
            // but for a clean migration:
            // $table->dropColumn('member_type');
        });
    }
};
