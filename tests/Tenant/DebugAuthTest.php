<?php

use App\Models\Staff;

it('debug: check auth with sanctum guard + test tenant host', function () {
    $user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($user, 'sanctum');

    $response = $this->getJson('http://test.mfukopro.test/api/v1/tenant/debug-auth');

    $response->assertStatus(200);
});
