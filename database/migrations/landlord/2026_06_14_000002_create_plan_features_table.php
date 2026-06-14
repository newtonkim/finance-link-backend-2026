<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('master')->create('plan_features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Seed the 10 existing features
        $now = now();
        DB::connection('master')->table('plan_features')->insert([
            ['key' => 'reports',          'name' => 'Monthly statements',    'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'loans',            'name' => 'Loans & SACCO module',  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'savings',          'name' => 'Core ledger & savings', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'shares',           'name' => 'Shares module',         'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'nfc',              'name' => 'NFC offline payments',  'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'api',              'name' => 'REST API access',       'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'sso',              'name' => 'SSO & audit logs',      'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'whitelabel',       'name' => 'White-label & SSO',     'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'email_support',    'name' => 'Email support',         'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'priority_support', 'name' => 'Priority support',      'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::connection('master')->dropIfExists('plan_features');
    }
};
