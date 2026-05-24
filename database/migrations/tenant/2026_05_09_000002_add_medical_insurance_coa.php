<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('chart_of_accounts')->where('gl_code', '5240')->exists()) {
            return;
        }

        $parent = DB::table('chart_of_accounts')->where('gl_code', '5200')->first();

        DB::table('chart_of_accounts')->insert([
            'gl_code'         => '5240',
            'name'            => 'Medical & Health Insurance',
            'account_type'    => 'EXPENSE',
            'account_subtype' => 'Staff Cost',
            'normal_balance'  => 'DR',
            'level'           => 3,
            'parent_id'       => $parent?->id,
            'is_control'      => false,
            'is_postable'     => true,
            'allow_manual'    => true,
            'is_active'       => true,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('chart_of_accounts')->where('gl_code', '5240')->delete();
    }
};
