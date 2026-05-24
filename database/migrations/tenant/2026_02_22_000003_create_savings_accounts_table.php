<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('savings_accounts')) {
            return;
        }

        Schema::create('savings_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->nullable()->index();
            $table->string('account_no', 50)->unique()->nullable()->index();
            $table->string('code', 50)->unique()->index();
            $table->string('account_type', 40)->default('voluntary')->index(); // mandatory, voluntary, fixed
            $table->decimal('balance', 15, 2)->default(0)->index();
            $table->decimal('interest_rate', 5, 2)->default(0)->index();
            $table->string('status')->default('active')->index(); // active, dormant, closed
            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->index();
            $table->softDeletes();

            $table->foreign('member_id')->references('id')->on('members')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('savings_accounts');
    }
};
