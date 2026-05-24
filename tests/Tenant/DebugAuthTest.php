<?php

use App\Models\Staff;
use Illuminate\Support\Facades\DB;

it('debug: check auth with sanctum guard + test tenant host', function () {
    $user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($user, 'sanctum');
    
    DB::connection('mysql')->table('tenants')->insertOrIgnore([
        'id' => 'test',
        'name' => 'Test Tenant',
        'subdomain' => 'test',
        'database_name' => config('database.connections.mysql.database'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/debug-auth');

    $content = json_decode($response->getContent(), true);
    dump($content);
    $response->assertStatus(200);
});
