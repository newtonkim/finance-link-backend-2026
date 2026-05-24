<?php

namespace Database\Seeders;

use App\Central\Models\Role;
use Illuminate\Database\Seeder;

class LandlordRoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Super_admin',
                'description' => 'System administrator with full access',
            ],
            [
                'name' => 'Manager',
                'description' => 'System manager with elevated permissions',
            ],
            [
                'name' => 'Accountant',
                'description' => 'User responsible for financial operations',
            ],
            [
                'name' => 'Staff',
                'description' => 'Standard system staff with limited access',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                ['description' => $role['description']]
            );
        }
    }
}
