<?php

namespace Tests\Feature\Tenant;

use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class ChartOfAccountTest extends TenantTestCase
{
    protected Staff $staff;

    protected string $domain = 'test-sacco.mfukopro.test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Tenant Admin',
            'email' => 'admin@test-sacco.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);
    }

    public function test_staff_can_view_chart_of_accounts(): void
    {
        ChartOfAccount::create([
            'gl_code' => '10000',
            'name' => 'Asset Account',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
            'is_control' => true,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->get('/api/v1/tenant/chart-of-accounts');

        $response->assertStatus(200);
    }

    public function test_staff_can_create_account(): void
    {
        $data = [
            'gl_code' => '11000',
            'name' => 'Cash at Hand',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'is_postable' => true,
            'is_active' => true,
        ];

        $response = $this->actingAs($this->staff, 'tenant')
            ->post('/api/v1/tenant/chart-of-accounts', $data);

        $response->assertStatus(201);
        $this->assertDatabaseHas('chart_of_accounts', [
            'gl_code' => '11000',
            'name' => 'Cash at Hand',
        ]);
    }

    public function test_staff_can_update_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '12000',
            'name' => 'Old Name',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->put("/api/v1/tenant/chart-of-accounts/{$account->id}", [
                'gl_code' => '12000',
                'name' => 'New Name',
                'account_type' => 'ASSET',
                'normal_balance' => 'DR',
            ]);

        $response->assertOk();
        $this->assertEquals('New Name', $account->fresh()->name);
    }

    public function test_staff_can_delete_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '13000',
            'name' => 'To Delete',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->delete("/api/v1/tenant/chart-of-accounts/{$account->id}");

        $response->assertOk();
        $this->assertSoftDeleted('chart_of_accounts', ['id' => $account->id]);
    }

    public function test_cannot_delete_account_with_children(): void
    {
        $parent = ChartOfAccount::create([
            'gl_code' => '10000',
            'name' => 'Parent',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'level' => 1,
        ]);

        ChartOfAccount::create([
            'gl_code' => '10001',
            'name' => 'Child',
            'account_type' => 'ASSET',
            'normal_balance' => 'DR',
            'parent_id' => $parent->id,
            'level' => 2,
        ]);

        $response = $this->actingAs($this->staff, 'tenant')
            ->delete("/api/v1/tenant/chart-of-accounts/{$parent->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('chart_of_accounts', ['id' => $parent->id, 'deleted_at' => null]);
    }
}
