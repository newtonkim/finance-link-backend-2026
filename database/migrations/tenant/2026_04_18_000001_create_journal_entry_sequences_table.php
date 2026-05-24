<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('journal_entry_sequences')) {
            return;
        }

        Schema::connection('tenant')->create('journal_entry_sequences', function (Blueprint $table) {
            $table->string('date_prefix', 8)->primary(); // YYYYMMDD
            $table->unsignedInteger('seq')->default(0);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('journal_entry_sequences');
    }
};
