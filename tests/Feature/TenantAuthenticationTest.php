<?php

namespace Tests\Feature;

use App\Domain\Tenancy\Entities\Tenant;
use App\Models\PlatformUser;
use App\Models\Staff;
use Tests\Concerns\RefreshTenantDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

class TenantAuthenticationTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup central domain for resolver
        Config::set('app.central_domains', ['mfukopro.test', 'localhost']);
    }

    public function test_a_tenant_staff_member_can_login_via_tenant_subdomain(): void
    {
        $id = 'tenant-' . uniqid();
        // 1. Create a tenant
        $tenant = Tenant::create([
            'id' => $id,
            'name' => 'Wazalendo Sacco',
            'subdomain' => $id,
            'database_name' => config('database.connections.mysql.database'),
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('tenants', ['subdomain' => $id]);

        // 2. Create a staff user
        $staff = Staff::factory()->create([
            'email' => "staff@{$id}.com",
            'password' => bcrypt('password123'),
        ]);

        $this->assertDatabaseHas('staff', ['email' => "staff@{$id}.com"], 'tenant');

        // 3. Attempt login via subdomain
        $response = $this->post("http://{$id}.mfukopro.test/login", [
                'email' => "staff@{$id}.com",
                'password' => 'password123',
            ]);

        // 4. Assert success
        $response->assertRedirect();
        $this->assertAuthenticatedAs($staff, 'tenant');
    }

    public function test_a_platform_user_cannot_login_via_tenant_subdomain(): void
    {
        $id = 'tenant-' . uniqid();
        // 1. Create a tenant
        $tenant = Tenant::create([
            'id' => $id,
            'name' => 'Wazalendo Sacco',
            'subdomain' => $id,
            'database_name' => config('database.connections.mysql.database'),
            'status' => 'active',
        ]);

        // 2. Create a platform user (central user)
        $platformUser = PlatformUser::factory()->create([
            'email' => "admin@{$id}.com",
            'password' => bcrypt('password123'),
        ]);

        // 3. Attempt login via tenant subdomain
        $response = $this->post("http://{$id}.mfukopro.test/login", [
                'email' => "admin@{$id}.com",
                'password' => 'password123',
            ]);

        // 4. Assert failure
        $response->assertSessionHasErrors('email');
        $this->assertGuest('tenant');
    }

    public function test_a_tenant_staff_cannot_login_via_central_domain(): void
    {
        $id = 'tenant-' . uniqid();
        // 1. Create a tenant staff
        $staff = Staff::factory()->create([
            'email' => "staff-{$id}@wazalendo.com",
            'password' => bcrypt('password123'),
        ]);

        // 2. Attempt login via central domain
        $response = $this->post("http://mfukopro.test/login", [
                'email' => "staff-{$id}@wazalendo.com",
                'password' => 'password123',
            ]);

        // 3. Assert failure
        $response->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->assertGuest('tenant');
    }

    public function test_tenant_without_database_name_throws_exception(): void
    {
        $id = 'broken-' . uniqid();
        // 1. Create a tenant without a database name
        $tenant = Tenant::create([
            'id' => $id,
            'name' => 'Broken Tenant',
            'subdomain' => $id,
            'database_name' => '', // Missing
            'status' => 'active',
        ]);

        // 2. Attempt to access a route that triggers SetTenantDatabase middleware
        $response = $this->get("http://{$id}.mfukopro.test/login");

        // 3. Assert error
        $response->assertStatus(500);
    }
}
