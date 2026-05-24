<?php

use App\Models\Member;
use App\Models\Scopes\BranchReadScope;
use App\Models\Staff;
use App\Support\BranchContext;
use App\Support\BranchQuery;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Services\TenantStaffService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function memberRow(string $name, int $branchId, string $suffix = ''): array
{
    return [
        'name' => $name,
        'code' => strtolower($name).$suffix,
        'email' => strtolower($name).$suffix.'@test.com',
        'password' => bcrypt('secret'),
        'branch_id' => $branchId,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function ensureBranches(array $branchIds): void
{
    $rows = collect($branchIds)
        ->unique()
        ->map(fn (int $branchId) => [
            'id' => $branchId,
            'name' => "Branch {$branchId}",
            'code' => "B{$branchId}",
            'is_active' => true,
            'system_type' => $branchId === 1 ? 'system' : 'user_created',
            'created_at' => now(),
            'updated_at' => now(),
        ])
        ->all();

    DB::connection('tenant')->table('branches')->insertOrIgnore($rows);
}

function ensureRole(string $name = 'teller', string $scope = BranchContext::SCOPE_SELF): ?int
{
    if (! DB::connection('tenant')->getSchemaBuilder()->hasTable('roles')) {
        return null;
    }

    DB::connection('tenant')->table('roles')->insertOrIgnore([
        'name' => $name,
        'description' => ucfirst(str_replace('_', ' ', $name)),
        'branch_scope' => $scope,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return DB::connection('tenant')->table('roles')->where('name', $name)->value('id');
}

function createMemberForBranch(int $branchId, string $suffix): int
{
    ensureBranches([$branchId]);

    return DB::connection('tenant')->table('members')->insertGetId(memberRow("TxnMember{$suffix}", $branchId, $suffix));
}

function transactionRow(string $reference, int $branchId, string $type, int|string $amount, ?int $memberId = null): array
{
    $memberId ??= createMemberForBranch($branchId, strtolower($reference));

    return [
        'reference' => $reference,
        'receipt_number' => $reference,
        'member_id' => $memberId,
        'branch_id' => $branchId,
        'type' => $type,
        'amount' => $amount,
        'transaction_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function makeBranchStaff(int $branchId, bool $isAdmin = false): Staff
{
    ensureBranches([$branchId]);

    return Staff::factory()->make([
        'branch_id' => $branchId,
        'is_tenant_admin' => $isAdmin,
        'role' => 'teller',
    ]);
}

beforeEach(function () {
    ensureBranches([1, 2, 3]);
    ensureRole('teller', BranchContext::SCOPE_SELF);
    ensureRole('branch_manager', BranchContext::SCOPE_BRANCH);
});

// ---------------------------------------------------------------------------
// READ SCOPE — BranchReadScope via trait
// ---------------------------------------------------------------------------

it('branch staff cannot read members from another branch', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('Alice', 1),
        memberRow('Bob', 2),
    ]);

    test()->actingAs(makeBranchStaff(1), 'sanctum');

    $members = Member::all();
    expect($members)->toHaveCount(1);
    expect($members->first()->name)->toBe('Alice');
});

it('admin staff sees all members across branches', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('Alice', 1, 'a'),
        memberRow('Bob', 2, 'a'),
    ]);

    test()->actingAs(makeBranchStaff(1, true), 'sanctum');

    expect(Member::all())->toHaveCount(2);
});

it('BranchReadScope does not filter when no authenticated user (command/seeder context)', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('Alice', 1, 'b'),
        memberRow('Bob', 2, 'b'),
    ]);

    // No actingAs — simulates Artisan command / seeder context
    expect(Member::all())->toHaveCount(2);
});

it('staff with no branch assigned sees no members', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('Alice', 1, 'c'),
    ]);

    $staff = Staff::factory()->make(['branch_id' => null, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    expect(Member::all())->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// WRITE ISOLATION — branch_id stamping on create
// ---------------------------------------------------------------------------

it('creates a member stamped with the acting staff branch_id', function () {
    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    $member = Member::create([
        'name' => 'Carol',
        'code' => 'carol_write',
        'email' => 'carol_write@test.com',
        'password' => bcrypt('secret'),
    ]);

    expect((int) $member->branch_id)->toBe(1);
});

it('does not overwrite an explicitly set branch_id on create', function () {
    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    $member = Member::create([
        'name' => 'Dave',
        'code' => 'dave_write',
        'email' => 'dave_write@test.com',
        'password' => bcrypt('secret'),
        'branch_id' => 2,
    ]);

    expect((int) $member->branch_id)->toBe(2);
});

// ---------------------------------------------------------------------------
// allowedBranchIds — primary branch + pivot/JSON
// ---------------------------------------------------------------------------

it('allowedBranchIds returns only the assigned branch for regular staff', function () {
    $staff = Staff::factory()->make(['id' => 99, 'branch_id' => 3, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    expect(BranchContext::allowedBranchIds())->toBe([3]);
});

it('allowedBranchIds returns empty array when staff has no branch', function () {
    $staff = Staff::factory()->make(['branch_id' => null, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    expect(BranchContext::allowedBranchIds())->toBe([]);
});

it('allowedBranchIds merges JSON branches for staff with extra access', function () {
    ensureBranches([1, 2]);

    $staff = Staff::factory()->make([
        'branch_id' => 1,
        'is_tenant_admin' => false,
        'branch_can_be_accessed' => [2]
    ]);
    test()->actingAs($staff, 'sanctum');

    $ids = BranchContext::allowedBranchIds();
    sort($ids);
    expect($ids)->toBe([1, 2]);
});

// ---------------------------------------------------------------------------
// BranchQuery helper — actor-owned tables (Transaction)
// ---------------------------------------------------------------------------

it('BranchQuery::apply filters transactions to the acting staff branch', function () {
    DB::connection('tenant')->table('transactions')->insert([
        transactionRow('BRQ-DEP-1', 1, 'deposit', 100),
        transactionRow('BRQ-DEP-2', 2, 'deposit', 200),
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    $results = BranchQuery::apply(Transaction::query())->get();
    expect($results)->toHaveCount(1);
    expect((int) $results->first()->branch_id)->toBe(1);
});

it('BranchQuery::apply does not filter for admin staff', function () {
    DB::connection('tenant')->table('transactions')->insert([
        transactionRow('BRQ-WDR-1', 1, 'withdrawal', 50),
        transactionRow('BRQ-WDR-2', 2, 'withdrawal', 75),
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => true]);
    test()->actingAs($staff, 'sanctum');

    $results = BranchQuery::apply(Transaction::query())->get();
    expect($results->count())->toBeGreaterThanOrEqual(2);
});

it('BranchQuery::apply does not filter when no authenticated user', function () {
    DB::connection('tenant')->table('transactions')->insert([
        transactionRow('BRQ-CHG-1', 1, 'charge', 10),
        transactionRow('BRQ-CHG-2', 2, 'charge', 10),
    ]);

    // No actingAs
    $results = BranchQuery::apply(Transaction::query())->get();
    expect($results->count())->toBeGreaterThanOrEqual(2);
});

// ---------------------------------------------------------------------------
// scopeFor — roles table lookup
// ---------------------------------------------------------------------------

it('scopeFor returns SCOPE_ALL for tenant admin without DB query', function () {
    $staff = Staff::factory()->make(['is_tenant_admin' => true]);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_ALL);
});

it('scopeFor returns SCOPE_SELF as safe default when roles table has no matching role', function () {
    $staff = Staff::factory()->make([
        'is_tenant_admin' => false,
        'role' => 'nonexistent_role_xyz',
    ]);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_SELF);
});

it('scopeFor reads branch_scope from roles table when column exists', function () {
    DB::connection('tenant')->table('roles')->insertOrIgnore([
        'name' => 'branch_manager',
        'branch_scope' => BranchContext::SCOPE_BRANCH,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $staff = Staff::factory()->make([
        'is_tenant_admin' => false,
        'role' => 'branch_manager',
    ]);

    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_BRANCH);
});

// ---------------------------------------------------------------------------
// API endpoint — members list respects branch isolation
// ---------------------------------------------------------------------------

it('GET /members returns only the authenticated staff branch records', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('EndpointAlice', 1, 'ep'),
        memberRow('EndpointBob', 2, 'ep'),
    ]);

    $staff = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false]);

    $response = test()->actingAs($staff, 'sanctum')
        ->getJson('/api/v1/tenant/members');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('EndpointAlice');
    expect($names)->not->toContain('EndpointBob');
});

it('GET /members returns all records for admin staff', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('EndpointCarol', 1, 'ep2'),
        memberRow('EndpointDave', 2, 'ep2'),
    ]);

    $staff = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => true]);

    $response = test()->actingAs($staff, 'sanctum')
        ->getJson('/api/v1/tenant/members');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();
    expect($names)->toContain('EndpointCarol');
    expect($names)->toContain('EndpointDave');
});

// ---------------------------------------------------------------------------
// Legacy service path — staffListCollection raw DB query
// ---------------------------------------------------------------------------

it('staffListCollection (legacy path) scopes to acting staff branch', function () {
    $roleId = ensureRole('legacy_teller', BranchContext::SCOPE_SELF);

    // Insert staff rows via tenant connection
    DB::connection('tenant')->table('staff')->insert([
        [
            'name' => 'BranchOneTeller', 'email' => 'b1teller@test.com',
            'password' => bcrypt('secret'), 'branch_id' => 1,
            'role' => 'legacy_teller', 'role_id' => $roleId, 'is_tenant_admin' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'name' => 'BranchTwoTeller', 'email' => 'b2teller@test.com',
            'password' => bcrypt('secret'), 'branch_id' => 2,
            'role' => 'legacy_teller', 'role_id' => $roleId, 'is_tenant_admin' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $actingStaff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false]);
    test()->actingAs($actingStaff, 'sanctum');

    // Simulate a request with status=all (required by staffListCollection)
    request()->merge(['status' => 'all']);

    $service = app(TenantStaffService::class);
    $result = $service->staffListCollection();

    // staffListCollection returns a LengthAwarePaginator (or an error array if it threw).
    if ($result instanceof LengthAwarePaginator) {
        $items = $result->getCollection();
    } else {
        $items = collect(data_get($result, 'data.data') ?? data_get($result, 'data') ?? []);
    }

    $names = $items->pluck('staff_fall_name')->all();

    expect($names)->toContain('BranchOneTeller');
    expect($names)->not->toContain('BranchTwoTeller');
});

it('staffListCollection returns all staff for admin', function () {
    $roleId = ensureRole('legacy_admin_staff', BranchContext::SCOPE_SELF);

    DB::connection('tenant')->table('staff')->insert([
        [
            'name' => 'AdminBranchA', 'email' => 'adminba@test.com',
            'password' => bcrypt('secret'), 'branch_id' => 1,
            'role' => 'legacy_admin_staff', 'role_id' => $roleId, 'is_tenant_admin' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ],
        [
            'name' => 'AdminBranchB', 'email' => 'adminbb@test.com',
            'password' => bcrypt('secret'), 'branch_id' => 2,
            'role' => 'legacy_admin_staff', 'role_id' => $roleId, 'is_tenant_admin' => false, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ],
    ]);

    $actingStaff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => true]);
    test()->actingAs($actingStaff, 'sanctum');

    request()->merge(['status' => 'all']);

    $service = app(TenantStaffService::class);
    $result = $service->staffListCollection();

    if ($result instanceof LengthAwarePaginator) {
        $items = $result->getCollection();
    } else {
        $items = collect(data_get($result, 'data.data') ?? data_get($result, 'data') ?? []);
    }

    $names = $items->pluck('staff_fall_name')->all();

    expect($names)->toContain('AdminBranchA');
    expect($names)->toContain('AdminBranchB');
});

// ---------------------------------------------------------------------------
// Test 1 of 5 — X-Acting-Branch-Id: admin reads & stamps scoped to header branch
// ---------------------------------------------------------------------------

it('admin with X-Acting-Branch-Id reads only that branch and stamps writes to it', function () {
    // Arrange: two members in different branches
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('ActingAlice', 1, 'act'),
        memberRow('ActingBob', 2, 'act'),
    ]);

    $admin = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => true]);
    test()->actingAs($admin, 'sanctum');

    // Simulate the X-Acting-Branch-Id header pointing at branch 2 (write context)
    app('request')->headers->set('X-Acting-Branch-Id', '2');

    // Write: actingBranchId() stamps new records with the header branch
    $actingBranchId = BranchContext::actingBranchId();
    expect($actingBranchId)->toBe(2);

    // Read filter: filterBranchId() reads ?branch_id= query param, not the header
    request()->merge(['branch_id' => 2]);
    $filteredBranchId = BranchContext::filterBranchId();
    expect($filteredBranchId)->toBe(2);

    // Create a member — should be stamped with branch 2 via actingBranchId()
    $member = Member::create([
        'name' => 'ActingNew',
        'code' => 'actingnew',
        'email' => 'actingnew@test.com',
        'password' => bcrypt('secret'),
    ]);
    expect((int) $member->branch_id)->toBe(2);
});

// ---------------------------------------------------------------------------
// Test 2 of 5 — Role with branch_scope=branch gives SCOPE_BRANCH behaviour
// ---------------------------------------------------------------------------

it('staff with role branch_scope=branch is restricted to their own branch', function () {
    // Seed a branch_manager role with SCOPE_BRANCH
    DB::connection('tenant')->table('roles')->insertOrIgnore([
        'name' => 'branch_manager_iso',
        'branch_scope' => BranchContext::SCOPE_BRANCH,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Insert members in two branches, bypassing scope
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('BmAlice', 1, 'bm'),
        memberRow('BmBob', 2, 'bm'),
    ]);

    $staff = Staff::factory()->make([
        'branch_id' => 1,
        'is_tenant_admin' => false,
        'role' => 'branch_manager_iso',
    ]);
    test()->actingAs($staff, 'sanctum');

    // scopeFor should resolve to SCOPE_BRANCH via the DB lookup
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_BRANCH);

    // Read scope: should only see branch 1 members
    $members = Member::all();
    $names = $members->pluck('name')->all();
    expect($names)->toContain('BmAlice');
    expect($names)->not->toContain('BmBob');
});

// ---------------------------------------------------------------------------
// Test 3 of 5 — Multi-branch via JSON: sees primary + extra, NOT a third
// ---------------------------------------------------------------------------

it('multi-branch staff sees primary and extra branch but not a third', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        memberRow('PivotAlice', 1, 'piv'),
        memberRow('PivotBob', 2, 'piv'),
        memberRow('PivotCharlie', 3, 'piv'),
    ]);

    $staff = Staff::factory()->make([
        'branch_id' => 1, 
        'is_tenant_admin' => false,
        'branch_can_be_accessed' => [2]
    ]);
    test()->actingAs($staff, 'sanctum');

    // allowedBranchIds should be [1, 2] — not 3
    $ids = BranchContext::allowedBranchIds();
    sort($ids);
    expect($ids)->toBe([1, 2]);

    // BranchReadScope filters accordingly
    $members = Member::all();
    $names = $members->pluck('name')->all();
    expect($names)->toContain('PivotAlice');
    expect($names)->toContain('PivotBob');
    expect($names)->not->toContain('PivotCharlie');
});

// ---------------------------------------------------------------------------
// Test 4 of 5 — TenantStaffController API endpoint isolation
// ---------------------------------------------------------------------------

it('GET /staff returns only acting staff branch via Eloquent scope', function () {
    // Create persisted staff in two different branches
    $branch1Staff = Staff::factory()->create([
        'name' => 'StaffEndpointBranch1',
        'branch_id' => 1,
        'is_tenant_admin' => false,
    ]);
    Staff::factory()->create([
        'name' => 'StaffEndpointBranch2',
        'branch_id' => 2,
        'is_tenant_admin' => false,
    ]);

    $response = test()->actingAs($branch1Staff, 'sanctum')
        ->getJson('/api/v1/tenant/staff');

    $response->assertOk();
    $names = collect($response->json('data') ?? $response->json())->pluck('name')->all();

    expect($names)->toContain('StaffEndpointBranch1');
    expect($names)->not->toContain('StaffEndpointBranch2');
});

it('GET /staff returns all branches for admin', function () {
    $admin = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => true]);
    Staff::factory()->create(['name' => 'AdminVisibleB2', 'branch_id' => 2, 'is_tenant_admin' => false]);

    $response = test()->actingAs($admin, 'sanctum')
        ->getJson('/api/v1/tenant/staff');

    $response->assertOk();
    $names = collect($response->json('data') ?? $response->json())->pluck('name')->all();
    expect($names)->toContain('AdminVisibleB2');
});

// ---------------------------------------------------------------------------
// Test 5 of 5 — Transaction reversal stamps branch_id on the reversal transaction
// ---------------------------------------------------------------------------

it('reversal transaction is stamped with the acting staff branch_id', function () {
    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false]);
    test()->actingAs($staff, 'sanctum');

    $memberId = createMemberForBranch(1, 'reversal');

    // Create an original transaction in branch 1 (boot hook stamps it)
    $original = Transaction::create([
        'reference' => 'REV-ORIG-1',
        'receipt_number' => 'REV-ORIG-1',
        'type' => 'deposit',
        'amount' => 500.00,
        'member_id' => $memberId,
        'narration' => 'Original deposit',
        'transaction_date' => now(),
    ]);

    expect((int) $original->branch_id)->toBe(1);

    // Simulate reversal: create a mirroring reversal transaction
    $reversal = Transaction::create([
        'reference' => 'REV-REV-1',
        'receipt_number' => 'REV-REV-1',
        'type' => 'withdrawal',
        'amount' => 500.00,
        'member_id' => $memberId,
        'narration' => 'Reversal of '.$original->id,
        'transaction_date' => now(),
        'reversal_of' => $original->id,
        'is_reversed' => false,
    ]);

    // Reversal transaction must be stamped with the same branch (from actingBranchId)
    expect((int) $reversal->branch_id)->toBe(1);

    // And the two transactions are in the same branch
    expect((int) $original->branch_id)->toBe((int) $reversal->branch_id);
});
