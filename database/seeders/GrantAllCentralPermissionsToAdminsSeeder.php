<?php

namespace Database\Seeders;

use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GrantAllCentralPermissionsToAdminsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $emails = [
            'admin@gmail.com',
            'newtonyamu22@gmail.com',
            'test@example.com',
        ];

        // Fetch all permission IDs from master
        $allPermissionIds = DB::connection('master')
            ->table('permissions')
            ->pluck('id')
            ->toArray();

        // Short-circuit if none exist
        if (empty($allPermissionIds)) {
            $this->command?->warn('No permissions found in master.permissions. Seed permissions first.');

            return;
        }

        foreach ($emails as $email) {
            $user = PlatformUser::on('master')->where('email', $email)->first();
            if (! $user) {
                $this->command?->warn("PlatformUser not found for email: {$email}");

                continue;
            }

            DB::connection('master')->table('permissions_users')->updateOrInsert(
                ['user_id' => $user->id],
                [
                    'permission_ids' => json_encode($allPermissionIds),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $this->command?->info("Granted all central permissions to {$email} (user_id={$user->id}).");
        }
    }
}
