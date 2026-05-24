<?php

use App\Models\Staff;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->user, 'sanctum');
    
    DB::connection('mysql')->table('tenants')->insertOrIgnore([
        'id' => 'test',
        'name' => 'Test Tenant',
        'subdomain' => 'test',
        'database_name' => config('database.connections.mysql.database'),
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('can list savings groups', function () {
    SavingsGroup::factory()->count(2)->create();

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->getJson('/api/v1/tenant/savings-groups');

    $response->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

it('can create a savings group', function () {
    $data = [
        'name' => 'Community Group',
        'primary_contact_country_code' => 'UG',
        'primary_contact_phone' => '0700111222',
        'other_contact_country_code' => 'UG',
        'date_created' => now()->toDateString(),
        'location' => 'Kampala',
        'description' => 'A test community group',
        'status' => 'active',
    ];

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/savings-groups', $data);

    $response->assertStatus(201);
    $this->assertDatabaseHas('savings_groups', ['name' => 'Community Group'], 'tenant');
});

it('can update a savings group', function () {
    $group = SavingsGroup::factory()->create(['name' => 'Old Group']);

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->putJson("/api/v1/tenant/savings-groups/{$group->id}", [
            'name' => 'New Group',
            'primary_contact_country_code' => 'UG',
            'primary_contact_phone' => '0700111222',
            'other_contact_country_code' => 'UG',
            'date_created' => now()->toDateString(),
            'location' => 'Entebbe',
            'description' => 'Updated description',
        ]);

    $response->assertStatus(200);
    expect($group->fresh()->name)->toBe('New Group');
});

it('can delete a savings group', function () {
    $group = SavingsGroup::factory()->create();

    $response = $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->deleteJson("/api/v1/tenant/savings-groups/{$group->id}");

    $response->assertStatus(200);
    $this->assertSoftDeleted('savings_groups', ['id' => $group->id], 'tenant');
});
