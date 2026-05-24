<?php

namespace Database\Seeders;

use App\Models\PlatformUser;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        PlatformUser::updateOrCreate(['email' => 'test@example.com'], [
            'name' => 'Test User',
            'password' => 'password',
        ]);

        $this->call(
            [
                LandlordSeeder::class,
                PermissionSeeder::class,
                SaccoSettingsSeeder::class,
            ]
        );
    }
}
