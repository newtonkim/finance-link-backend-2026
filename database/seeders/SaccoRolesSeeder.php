<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SaccoRolesSeeder extends Seeder
{
    public function run(): void
    {
        $permissionIds = DB::connection('tenant')
            ->table('permissions')
            ->pluck('id')
            ->toArray();

        $row = [
            'name' => 'admin',
            'code' => 'CODE-0000000001',
            'description' => 'admin',
            'default_permissions' => json_encode($permissionIds),
            'system_type' => 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::connection('tenant')->table('roles')->insertOrIgnore([$row]);
    }
}
