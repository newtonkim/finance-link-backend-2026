<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('shares', function (Blueprint $table) {
            if (! Schema::connection('tenant')->hasColumn('shares', 'buyer_payment_mode')) {
                $table->string('buyer_payment_mode')->nullable()->comment('Account, Cash')->after('share_no')->index();
                // $table->string('transaction_date')->nullable()->comment("Account, Cash")->after('created_at')->index();

            }
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('shares', function (Blueprint $table) {
            $table->dropColumn('buyer_payment_mode');
            //   $table->dropColumn('transaction_date');

        });
    }
};
