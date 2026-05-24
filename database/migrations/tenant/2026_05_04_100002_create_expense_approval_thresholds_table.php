<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_approval_thresholds', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_amount', 15, 2);
            $table->decimal('max_amount', 15, 2)->nullable();
            $table->string('required_role')->index()->comment('The Spatie role name required');
            $table->integer('level')->index()->comment('1, 2, 3, etc.');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_approval_thresholds');
    }
};
