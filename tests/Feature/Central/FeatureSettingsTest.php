<?php

namespace Tests\Feature\Central;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class FeatureSettingsTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.central_domain' => 'central.mfukopro.test',
            'app.central_domains' => ['central.mfukopro.test'],
        ]);
    }

    public function test_feature_can_be_updated_and_plan_feature_keys_are_renamed(): void
    {
        $admin = PlatformUser::create([
            'name' => 'Settings Admin',
            'email' => 'settings-admin-'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $featureId = DB::connection('master')->table('plan_features')->insertGetId([
            'name' => 'Old Feature',
            'key' => 'old_feature_'.uniqid(),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oldKey = DB::connection('master')
            ->table('plan_features')
            ->where('id', $featureId)
            ->value('key');
        $newKey = 'new_feature_'.uniqid();

        $planId = DB::connection('master')->table('plans')->insertGetId([
            'name' => 'Feature Rename Plan '.uniqid(),
            'slug' => 'feature-rename-plan-'.uniqid(),
            'price' => 1000,
            'billing_cycle' => 'monthly',
            'features' => json_encode([$oldKey => true, 'reports' => false]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->actingAs($admin, 'platform')
            ->postJson('/api/v1/central/settings/features/update', [
                'id' => $featureId,
                'name' => 'New Feature',
                'key' => $newKey,
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('plan_features', [
            'id' => $featureId,
            'name' => 'New Feature',
            'key' => $newKey,
        ], 'master');

        $features = json_decode(
            DB::connection('master')->table('plans')->where('id', $planId)->value('features'),
            true
        );

        $this->assertArrayNotHasKey($oldKey, $features);
        $this->assertTrue($features[$newKey]);
        $this->assertFalse($features['reports']);
    }
}
