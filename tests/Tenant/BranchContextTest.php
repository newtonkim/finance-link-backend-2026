<?php

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('actingBranchId returns staff branch_id for SCOPE_SELF user', function () {
    $staff = Staff::factory()->make(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    
    // Ensure roles table doesn't influence (fallback to SCOPE_SELF)
    expect(BranchContext::actingBranchId())->toBe(5);
});

it('actingBranchId ignores branch_id in request body for non-admin', function () {
    $staff = Staff::factory()->make(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    
    request()->merge(['branch_id' => 99]);
    expect(BranchContext::actingBranchId())->toBe(5);
});

it('actingBranchId ignores X-Acting-Branch-Id header for non-SCOPE_ALL user', function () {
    $staff = Staff::factory()->make(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    
    request()->headers->set('X-Acting-Branch-Id', '99');
    expect(BranchContext::actingBranchId())->toBe(5);
});

it('filterBranchId returns staff branch_id for SCOPE_SELF regardless of request param', function () {
    $staff = Staff::factory()->make(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    
    request()->merge(['branch_id' => 99]);
    expect(BranchContext::filterBranchId())->toBe(5);
});

it('scopeFor returns SCOPE_SELF for unknown role (safe fallback)', function () {
    $staff = Staff::factory()->make(['is_tenant_admin' => false, 'role' => 'unknown_role_xyz']);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_SELF);
});

it('scopeFor returns SCOPE_ALL for is_tenant_admin without DB lookup', function () {
    $staff = Staff::factory()->make(['is_tenant_admin' => true, 'role' => 'teller']);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_ALL);
});
