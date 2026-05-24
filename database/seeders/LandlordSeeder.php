<?php

namespace Database\Seeders;

use App\Central\Models\Plan;
use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class LandlordSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Default Plans
        Plan::updateOrCreate(['slug' => 'basic'], [
            'name' => 'Basic Plan',
            'price' => 29.99,
            'billing_cycle' => 'monthly',
            'max_members' => 100,
            'max_users' => 5,
            'features' => json_encode(['reports' => false, 'loans' => true, 'savings' => true, 'shares' => false]),
        ]);

        Plan::updateOrCreate(['slug' => 'standard'], [
            'name' => 'Standard Plan',
            'price' => 79.99,
            'billing_cycle' => 'monthly',
            'max_members' => 500,
            'max_users' => 20,
            'features' => json_encode(['reports' => true, 'loans' => true, 'savings' => true, 'shares' => true]),
        ]);

        Plan::updateOrCreate(['slug' => 'premium'], [
            'name' => 'Premium Plan',
            'price' => 149.99,
            'billing_cycle' => 'monthly',
            'max_members' => -1, // unlimited
            'max_users' => -1,  // unlimited
            'features' => json_encode(['reports' => true, 'loans' => true, 'savings' => true, 'shares' => true, 'api_access' => true]),
        ]);

        // 2. Create Initial Platform User (Super Admin)
        PlatformUser::updateOrCreate(['email' => 'admin@mfukopro.com'], [
            'name' => 'Super Admin',
            'password' => Hash::make('password'),
        ]);

        // 3. Seed Roles
        $this->call(LandlordRoleSeeder::class);

        // 4. Seed COA templates (used when provisioning new tenants)
        $this->call(SaccoCoaSeeder::class);
    }
}
