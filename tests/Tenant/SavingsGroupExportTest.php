<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->user = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->user, 'sanctum');
    
    // 3. Create the tenant record in the landlord DB (redirected to test DB)
    $tenant = \App\Domain\Tenancy\Entities\Tenant::updateOrCreate(['id' => 'test'], [
        'name' => 'Test Tenant',
        'subdomain' => 'test',
        'database_name' => config('database.connections.mysql.database'),
        'status' => 'active',
    ]);
});

it('can export group members to csv', function () {
    Excel::fake();

    $group = SavingsGroup::factory()->create();
    $members = Member::factory()->count(3)->create();
    $group->members()->attach($members->pluck('id'));

    $response = $this->getJson("http://test.mfukopro.test/api/v1/tenant/savings-groups/{$group->id}/members/export");

    $response->assertStatus(200);
    
    Excel::assertDownloaded(str_replace(' ', '_', $group->name).'_Members.xlsx');
});
