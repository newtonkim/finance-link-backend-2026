# Branch Enforcement Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enforce branch-level data isolation across all tenant API endpoints and frontend pages so tellers and branch managers only see and act on records within their permitted branch scope.

**Architecture:** A `BranchReadScope` Eloquent global scope (registered via `BelongsToAuthenticatedBranch` trait) handles model-layer read enforcement for branch-owned master tables. A `BranchQuery` helper handles the equivalent for raw `DB::table()` paths. `Transaction` (actor-owned activity table) uses `BranchQuery::scopeByActor` for SCOPE_SELF tellers — not the branch scope. `BranchContext` is refactored to cleanly separate acting-branch (writes) from filter-branch (reads). The frontend `branchStore` distributes auth-context branch state to all list pages and a top-nav branch switcher.

**Tech Stack:** Laravel 12, PHP 8.2, MySQL (tenant DB connection), Pest, Vue 3, Pinia, TypeScript

**Spec:** `docs/superpowers/specs/2026-03-24-branch-enforcement-design.md`

---

## Critical Design Note: SCOPE_SELF and Actor-Owned Tables

The spec (Section 6 Table Classification Matrix) distinguishes two table types:

| Type | Examples | SCOPE_SELF filter |
|------|----------|-------------------|
| Branch-owned master | `members`, `staff`, `savings_accounts`, `loans` | `WHERE branch_id IN (allowedIds)` |
| Actor-owned activity | `transactions`, approval logs, reversals | `WHERE created_by = auth()->id()` |

`BranchReadScope` handles master tables. The `Transaction` model is **excluded from the trait's global scope** (see Task 2) and instead uses `BranchQuery::scopeByActor()` explicitly in controllers that serve tellers.

---

## File Map

### New Files — Backend
| File | Responsibility |
|------|---------------|
| `app/Models/Scopes/BranchReadScope.php` | Eloquent global scope — auto-filters SELECTs on branch-owned master models |
| `app/Support/BranchQuery.php` | Query-builder scope helpers for `DB::table()` and actor-owned tables |
| `database/migrations/tenant/2026_03_24_000001_add_branch_scope_to_roles_table.php` | Adds `branch_scope` enum to `roles` |
| `database/migrations/tenant/2026_03_24_000002_drop_branch_id_from_global_tables.php` | Removes `branch_id` from non-business tables |
| `database/migrations/tenant/2026_03_24_000003_create_staff_branch_access_table.php` | Pivot for multi-branch staff access |
| `database/migrations/tenant/2026_03_24_000004_add_branch_reporting_indexes.php` | Composite indexes for report queries |
| `tests/Tenant/BranchContextTest.php` | Unit tests for BranchContext methods |
| `tests/Tenant/BranchIsolationTest.php` | Full branch isolation test suite |

### Modified Files — Backend
| File | Change |
|------|--------|
| `app/Support/BranchContext.php` | Add `actingBranchId()`, `filterBranchId()`, `allowedBranchIds()`; update `authContextFor()` to use `filterBranchId()`; update `scopeFor()` to DB lookup; update `availableBranchesFor()` for pivot; remove `currentBranchId()` |
| `app/Models/Concerns/BelongsToAuthenticatedBranch.php` | Register `BranchReadScope`; update `creating()` to use `actingBranchId()`. Does NOT apply to `Transaction` model (see Task 2) |
| `app/Http/Globals/GlobalHelpers.php` | Remove branch stamping block from `UpdateOrCreateRecord()` (~line 268) |
| `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` | Remove all three `$branchId = BranchContext::currentBranchId()` calls (deposit ~182, withdrawal ~329, charge ~546) and all `'branch_id' => $branchId` entries in `Transaction::create()` |
| `app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php` | Convert `DB::table('transactions')->insert()` to `Transaction::create()`; remove manual `branch_id` stamping (~lines 92, 115, 133) |
| `app/Tenant/Http/Controllers/Api/V1/DashboardController.php` | Add `withoutGlobalScope(BranchReadScope::class)` to all `Member::` and `Staff::` Eloquent calls |
| `app/Tenant/Http/Controllers/Api/V1/TenantStaffController.php` | Add `withoutGlobalScope()` to `index()` so admins see all staff |

### New Files — Frontend
| File | Responsibility |
|------|---------------|
| `src/stores/branchStore.ts` | Pinia store — holds scope, activeBranchId, availableBranches, derived flags |

### Modified Files — Frontend
| File | Change |
|------|--------|
| `src/stores/auth.ts` | Call `branchStore.initFromAuth()` after login/me response; `branchStore.reset()` on logout |
| Top nav layout component | Add branch switcher for `canSwitchBranch` users |
| All tenant list pages (Members, Staff, SavingsAccounts, Transactions, Loans) | Pass `branch_id` query param from `branchStore.activeBranchId` |

---

## Task 1: Refactor BranchContext — separate acting from filtering

**Spec step:** Step 1
**Files:**
- Modify: `app/Support/BranchContext.php`
- Create: `tests/Tenant/BranchContextTest.php`

### Background
Currently `currentBranchId()` conflates write-stamping and read-filtering in one method. This task splits it into purpose-specific methods and updates all internal callers before `currentBranchId()` is deleted.

New methods:
- `actingBranchId()` — stamps writes. Reads `X-Acting-Branch-Id` header only for `SCOPE_ALL` users.
- `filterBranchId()` — filters reads/reports. Reads `request()->input('branch_id')` only for `SCOPE_ALL`.
- `allowedBranchIds()` — returns the set of branch IDs the user may read (`null` = no restriction for SCOPE_ALL).
- Update `authContextFor()` to call `filterBranchId()` instead of `currentBranchId()`.
- Update `scopeFor()` to DB lookup (retaining `is_tenant_admin` fast path).
- **Delete `currentBranchId()`** — only after all callers above are updated in this same task.

- [ ] **Step 1: Write failing tests**

Create `tests/Tenant/BranchContextTest.php`:

```php
<?php

use App\Models\Staff;
use App\Support\BranchContext;

it('actingBranchId returns staff branch_id for SCOPE_SELF user', function () {
    $staff = Staff::factory()->make(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
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
    $this->withHeaders(['X-Acting-Branch-Id' => '99']);
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
```

Run: `php artisan test --filter=BranchContextTest`
Expected: FAIL — methods `actingBranchId`, `filterBranchId` do not exist yet.

- [ ] **Step 2: Add `actingBranchId()`, `filterBranchId()`, `allowedBranchIds()` to BranchContext**

Add these three methods to `app/Support/BranchContext.php`:

```php
/**
 * Branch this user acts on behalf of for WRITE operations.
 * Never reads from request body. SCOPE_ALL users may pass X-Acting-Branch-Id header.
 */
public static function actingBranchId(): ?int
{
    $user = Auth::user();
    if (! $user instanceof Staff) {
        return null;
    }

    if (static::scopeFor($user) === self::SCOPE_ALL) {
        $header = (int) request()->header('X-Acting-Branch-Id', 0);
        if ($header > 0 && self::branchExists($header)) {
            return $header;
        }
        return null; // admin with no acting branch = unscoped
    }

    return $user->branch_id ? (int) $user->branch_id : null;
}

/**
 * Branch to FILTER READ RESULTS by. Honoured from request param only for SCOPE_ALL users.
 */
public static function filterBranchId(): ?int
{
    $user = Auth::user();
    if (! $user instanceof Staff) {
        return null;
    }

    if (static::scopeFor($user) === self::SCOPE_ALL) {
        $requested = request()->input('branch_id');
        if ($requested !== null && self::branchExists((int) $requested)) {
            return (int) $requested;
        }
        return null;
    }

    return $user->branch_id ? (int) $user->branch_id : null;
}

/**
 * Set of branch IDs the user may read. Returns null for SCOPE_ALL (no restriction).
 */
public static function allowedBranchIds(): ?array
{
    $user = Auth::user();
    if (! $user instanceof Staff) {
        return null;
    }

    if (static::scopeFor($user) === self::SCOPE_ALL) {
        return null;
    }

    return $user->branch_id ? [(int) $user->branch_id] : [];
}
```

- [ ] **Step 3: Update `scopeFor()` to DB lookup**

Replace the hardcoded arrays in `scopeFor()` with a DB lookup. Keep `is_tenant_admin` fast path first:

```php
public static function scopeFor(?Staff $user): string
{
    if (! $user instanceof Staff) {
        return self::SCOPE_SELF;
    }

    if ((bool) $user->is_tenant_admin) {
        return self::SCOPE_ALL; // fast path, no DB query
    }

    if (Schema::connection('tenant')->hasTable('roles')) {
        $scope = DB::connection('tenant')
            ->table('roles')
            ->where('name', self::normalizedRoleNameFor($user))
            ->value('branch_scope');

        if ($scope !== null) {
            return $scope;
        }
    }

    return self::SCOPE_SELF; // safe default
}
```

> Note: The `branch_scope` column is added to `roles` in Task 5. Until then, the DB lookup returns `null` and falls back to `SCOPE_SELF` — existing behaviour is preserved.

- [ ] **Step 4: Update `authContextFor()` to use `filterBranchId()` instead of `currentBranchId()`**

In `authContextFor()`, replace line 48:
```php
// Before:
'active_branch_id' => self::currentBranchId(),

// After:
'active_branch_id' => self::filterBranchId(),
```

- [ ] **Step 5: Delete `currentBranchId()` from BranchContext**

Remove the entire `currentBranchId()` method. It has no remaining callers after Steps 2–4.

> **Important:** Do not delete until Steps 2–4 are done. Any remaining call to `currentBranchId()` will produce a fatal error.

- [ ] **Step 6: Run tests**

```bash
php artisan test --filter=BranchContextTest
```
Expected: All 6 tests PASS.

- [ ] **Step 7: Run full test suite to catch any remaining `currentBranchId()` callers**

```bash
php artisan test
```
If any test fails with `Call to undefined method BranchContext::currentBranchId()`, find the caller and update it to `actingBranchId()` or `filterBranchId()` as appropriate.

- [ ] **Step 8: Commit**

```bash
git add app/Support/BranchContext.php tests/Tenant/BranchContextTest.php
git commit -m "feat(branch): split actingBranchId/filterBranchId, DB-driven scopeFor, remove currentBranchId"
```

---

## Task 2: Create `BranchReadScope` for master tables + update trait

**Spec step:** Step 2
**Files:**
- Create: `app/Models/Scopes/BranchReadScope.php`
- Modify: `app/Models/Concerns/BelongsToAuthenticatedBranch.php`

### Background
`BranchReadScope` applies `WHERE branch_id IN (allowedIds)` for all non-SCOPE_ALL staff. It is registered in the trait.

**Critically:** `Transaction` is an **actor-owned activity table** (spec Section 6). SCOPE_SELF tellers should see only their *own* transactions (`WHERE created_by = id`), not all branch transactions. Therefore `Transaction` must be removed from the trait and handled separately by `BranchQuery` (Task 3). For now, `BranchReadScope` is safe to register on the 5 remaining master models: `Staff`, `Member`, `SavingsAccount`, `Loan`, `GeneralCharge`.

Also note: Artisan commands, seeders, and scheduled jobs that call these models without an authenticated user in context will hit `Auth::user() === null` → the scope returns early (no filter). This is the correct and safe behaviour for server-side operations.

- [ ] **Step 1: Write failing tests**

Create `tests/Tenant/BranchIsolationTest.php`:

```php
<?php

use App\Models\Member;
use App\Models\Staff;
use App\Models\Scopes\BranchReadScope;

it('branch staff cannot read members from another branch', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');

    $members = Member::all();
    expect($members)->toHaveCount(1);
    expect($members->first()->name)->toBe('Alice');
});

it('admin staff sees all members across branches', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => true]);
    $this->actingAs($staff, 'sanctum');

    expect(Member::all())->toHaveCount(2);
});

it('BranchReadScope does not filter when no authenticated user (command/seeder context)', function () {
    Member::withoutGlobalScope(BranchReadScope::class)->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // No actingAs — simulates Artisan command / seeder context
    expect(Member::all())->toHaveCount(2);
});
```

Run: `php artisan test --filter=BranchIsolationTest`
Expected: FAIL — `BranchReadScope` class does not exist.

- [ ] **Step 2: Create `app/Models/Scopes/BranchReadScope.php`**

Create the directory `app/Models/Scopes/` if it does not exist, then create the file:

```php
<?php

namespace App\Models\Scopes;

use App\Models\Staff;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

class BranchReadScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        // No authenticated user (Artisan, seeders, commands): no filter
        if (! $user instanceof Staff) {
            return;
        }

        // SCOPE_ALL admins: see everything
        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            return;
        }

        $allowedIds = BranchContext::allowedBranchIds();

        if (empty($allowedIds)) {
            // Staff with no branch assigned: see nothing rather than everything
            $builder->whereRaw('1 = 0');
            return;
        }

        $builder->whereIn($model->getTable() . '.branch_id', $allowedIds);
    }
}
```

- [ ] **Step 3: Update `BelongsToAuthenticatedBranch` trait**

Replace the full contents of `app/Models/Concerns/BelongsToAuthenticatedBranch.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\Scopes\BranchReadScope;
use App\Support\BranchContext;

trait BelongsToAuthenticatedBranch
{
    protected static function bootBelongsToAuthenticatedBranch(): void
    {
        // Enforce branch read scope on every SELECT for branch-owned master tables.
        // Do NOT add this trait to actor-owned activity tables (e.g. Transaction).
        static::addGlobalScope(new BranchReadScope);

        // Auto-stamp branch_id on INSERT via actingBranchId() — never from request body.
        static::creating(function ($model) {
            if (! empty($model->branch_id)) {
                return;
            }
            $branchId = BranchContext::actingBranchId();
            if ($branchId !== null) {
                $model->branch_id = $branchId;
            }
        });
    }
}
```

- [ ] **Step 4: Remove `BranchReadScope` from `Transaction` model — keep stamping hook**

Open `app/Tenant/Modules/Transactions/Models/Transaction.php`.

The `Transaction` model currently uses `BelongsToAuthenticatedBranch`. That trait registers both the `BranchReadScope` AND the `creating()` stamp hook. We need the stamp but NOT the read scope.

**Do this definitively:**
1. Remove `use BelongsToAuthenticatedBranch;` from `Transaction`.
2. Add a standalone `boot()` method to `Transaction` for branch stamping only:

```php
protected static function boot(): void
{
    parent::boot();

    static::creating(function ($model) {
        if (empty($model->branch_id)) {
            $branchId = \App\Support\BranchContext::actingBranchId();
            if ($branchId !== null) {
                $model->branch_id = $branchId;
            }
        }
    });
}
```

This preserves auto-stamping on `Transaction` while excluding it from `BranchReadScope`.

Only these 5 models should use the full trait: `Staff`, `Member`, `SavingsAccount`, `Loan`, `GeneralCharge`.

- [ ] **Step 5: Run tests**

```bash
php artisan test --filter=BranchIsolationTest
```
Expected: All 3 tests PASS.

- [ ] **Step 6: Run full test suite**

```bash
php artisan test
```
Expected: All pass. If any test breaks because a model query returns no results unexpectedly, check that the test has an authenticated user in context (`$this->actingAs(...)`) or add `->withoutGlobalScope(BranchReadScope::class)` to that test's setup query.

- [ ] **Step 7: Commit**

```bash
git add app/Models/Scopes/BranchReadScope.php \
        app/Models/Concerns/BelongsToAuthenticatedBranch.php \
        app/Tenant/Modules/Transactions/Models/Transaction.php \
        tests/Tenant/BranchIsolationTest.php
git commit -m "feat(branch): add BranchReadScope, register in trait, remove from Transaction model"
```

---

## Task 3: Create `BranchQuery` helper for query-builder and actor-owned tables

**Spec step:** Step 3
**Files:**
- Create: `app/Support/BranchQuery.php`

### Background
`DB::table()` and raw query-builder calls bypass Eloquent global scopes entirely. `BranchQuery` provides the same isolation logic for these paths. It is also the mechanism for `Transaction` teller isolation (actor scoping).

- [ ] **Step 1: Write failing tests**

Add to `tests/Tenant/BranchIsolationTest.php`:

```php
use App\Support\BranchQuery;
use Illuminate\Support\Facades\DB;

it('BranchQuery::scopeByBranch restricts query-builder query for branch staff', function () {
    DB::connection('tenant')->table('members')->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');

    $query = DB::connection('tenant')->table('members');
    BranchQuery::scopeByBranch($query);

    expect($query->count())->toBe(1);
    expect($query->first()->name)->toBe('Alice');
});

it('BranchQuery::scopeByBranch does not restrict for SCOPE_ALL admin', function () {
    DB::connection('tenant')->table('members')->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => true]);
    $this->actingAs($staff, 'sanctum');

    $query = DB::connection('tenant')->table('members');
    BranchQuery::scopeByBranch($query);

    expect($query->count())->toBe(2);
});

it('BranchQuery::scopeByActor restricts SCOPE_SELF teller to own transactions only', function () {
    $teller1 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $teller2 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);

    DB::connection('tenant')->table('transactions')->insert([
        ['amount' => 100, 'branch_id' => 1, 'created_by' => $teller1->id, 'created_at' => now(), 'updated_at' => now()],
        ['amount' => 200, 'branch_id' => 1, 'created_by' => $teller2->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($teller1, 'sanctum');

    $query = DB::connection('tenant')->table('transactions');
    BranchQuery::scopeByActor($query);

    expect($query->count())->toBe(1);
    expect($query->first()->amount)->toBe(100);
});

it('BranchQuery::scopeByVisibility shows SCOPE_BRANCH manager all branch transactions', function () {
    // Insert transactions from two different tellers in the same branch
    $teller1 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $teller2 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $manager  = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'manager']);

    DB::connection('tenant')->table('roles')->updateOrInsert(
        ['name' => 'manager'],
        ['branch_scope' => 'branch', 'created_at' => now(), 'updated_at' => now()]
    );

    DB::connection('tenant')->table('transactions')->insert([
        ['amount' => 100, 'branch_id' => 1, 'created_by' => $teller1->id, 'created_at' => now(), 'updated_at' => now()],
        ['amount' => 200, 'branch_id' => 1, 'created_by' => $teller2->id, 'created_at' => now(), 'updated_at' => now()],
        ['amount' => 300, 'branch_id' => 2, 'created_by' => $teller1->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($manager, 'sanctum');

    // scopeByVisibility with actorColumn: SCOPE_BRANCH falls through to branch scoping
    $query = DB::connection('tenant')->table('transactions');
    BranchQuery::scopeByVisibility($query, 'branch_id', 'created_by');

    // Manager sees all 2 transactions from their branch, not just their own
    expect($query->count())->toBe(2);
});
```

Run: `php artisan test --filter="BranchQuery"`
Expected: FAIL — `BranchQuery` class does not exist.

- [ ] **Step 2: Create `app/Support/BranchQuery.php`**

```php
<?php

namespace App\Support;

use App\Models\Staff;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;

class BranchQuery
{
    /**
     * Apply branch_id restriction to a query-builder instance.
     * Mutates the builder in place.
     */
    public static function scopeByBranch(Builder $query, string $branchColumn = 'branch_id'): void
    {
        $user = Auth::user();

        if (! $user instanceof Staff) {
            return; // central admin / unauthenticated — no restriction
        }

        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            return;
        }

        $allowedIds = BranchContext::allowedBranchIds();

        if (empty($allowedIds)) {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereIn($branchColumn, $allowedIds);
    }

    /**
     * Apply actor (created_by) restriction for SCOPE_SELF users.
     * Used for actor-owned activity tables (transactions, etc.).
     */
    public static function scopeByActor(Builder $query, string $actorColumn = 'created_by'): void
    {
        $user = Auth::user();

        if (! $user instanceof Staff) {
            return;
        }

        if (BranchContext::scopeFor($user) === BranchContext::SCOPE_ALL) {
            return;
        }

        $query->where($actorColumn, $user->id);
    }

    /**
     * Combined visibility: branch scope for master tables, actor scope for activity tables.
     * Pass $actorColumn = null to use branch-only scoping.
     */
    public static function scopeByVisibility(
        Builder $query,
        string $branchColumn = 'branch_id',
        ?string $actorColumn = null
    ): void {
        $user = Auth::user();

        if (! $user instanceof Staff) {
            return;
        }

        $scope = BranchContext::scopeFor($user);

        if ($scope === BranchContext::SCOPE_ALL) {
            return;
        }

        if ($scope === BranchContext::SCOPE_SELF && $actorColumn !== null) {
            $query->where($actorColumn, $user->id);
            return;
        }

        static::scopeByBranch($query, $branchColumn);
    }
}
```

- [ ] **Step 3: Run tests**

```bash
php artisan test --filter="BranchQuery"
```
Expected: All 3 tests PASS.

- [ ] **Step 4: Commit**

```bash
git add app/Support/BranchQuery.php tests/Tenant/BranchIsolationTest.php
git commit -m "feat(branch): add BranchQuery helper for query-builder and actor-owned table isolation"
```

---

## Task 4: Dashboard opt-out + TenantStaffController + consolidate stamping

**Spec steps:** Step 4, Step 5
**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/DashboardController.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/TenantStaffController.php`
- Modify: `app/Http/Globals/GlobalHelpers.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php`

### Part A — DashboardController: opt-out for cross-branch aggregations

- [ ] **Step 1: Add `withoutGlobalScope` to all Eloquent calls in DashboardController**

Add the import at the top:
```php
use App\Models\Scopes\BranchReadScope;
```

Wrap every `Member::` and `Staff::` Eloquent call with `withoutGlobalScope(BranchReadScope::class)`:
```php
// Example pattern — apply to ALL 5 Eloquent calls in this file:
Member::withoutGlobalScope(BranchReadScope::class)->count()
Staff::withoutGlobalScope(BranchReadScope::class)->count()
Member::withoutGlobalScope(BranchReadScope::class)->whereNull('deleted_at')->where('gender', 'male')->count()
Member::withoutGlobalScope(BranchReadScope::class)->whereNull('deleted_at')->where('gender', 'female')->count()
// ... and any remaining Member:: calls
```

> Note: `DB::connection('tenant')->table(...)` calls in DashboardController are NOT Eloquent — they are unaffected by global scopes and need no change here.

### Part B — TenantStaffController: admin sees all staff

- [ ] **Step 2: Update `TenantStaffController::index()`**

Add imports at the top:
```php
use App\Models\Scopes\BranchReadScope;
use App\Tenant\Http\Resources\StaffResource;
```

In the `index()` method, opt out of `BranchReadScope` and preserve the existing `StaffResource` response format:

```php
public function index(Request $request): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
{
    $query = Staff::withoutGlobalScope(BranchReadScope::class)
        ->orderBy('name');

    if ($request->filled('search')) {
        $search = $request->input('search');
        $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%");
        });
    }

    return StaffResource::collection($query->get());
}
```

> Why: Staff list in settings is an admin screen. The existing `StaffResource` transformer must be preserved so the frontend receives the expected response shape. The branch switcher (Task 11) passes `branch_id` as a query param for filtering, handled upstream.

### Part C — Consolidate branch stamping

- [ ] **Step 3: Remove branch stamping from `GlobalHelpers::UpdateOrCreateRecord()`**

In `app/Http/Globals/GlobalHelpers.php`, locate the block inside `UpdateOrCreateRecord()` that stamps `branch_id` (around line 268). The block looks like:

```php
// Find and delete this block (exact code may vary slightly):
if (/* hasColumn check */ && empty($dataToUpdate['branch_id'])) {
    $dataToUpdate['branch_id'] = BranchContext::currentBranchId();
}
```

Delete the entire block. Branch stamping is now exclusively handled by the model trait's `creating()` hook via `actingBranchId()`.

- [ ] **Step 4: Remove all three manual `$branchId` stamping locations in `SavingsAccountController`**

In `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`, find and remove:
1. `$branchId = BranchContext::currentBranchId();` at line ~182 (deposit method)
2. `'branch_id' => $branchId` from `Transaction::create([...])` in the deposit method
3. `$branchId = BranchContext::currentBranchId();` at line ~329 (withdrawal method)
4. `'branch_id' => $branchId` from `Transaction::create([...])` in the withdrawal method
5. `$branchId = BranchContext::currentBranchId();` at line ~546 (`applyManualCharge` method)
6. `'branch_id' => $branchId` from `Transaction::create([...])` in the charge method

The `Transaction` model's `creating()` hook (from the trait, still active after Task 2 Step 4 is corrected — see note below) stamps `branch_id` automatically.

> Note: In Task 2 Step 4, the `Transaction` model had the **BranchReadScope removed** — but the `creating()` hook from the trait is still active if the trait is still used. Verify whether `Transaction` still uses `BelongsToAuthenticatedBranch`. If yes, the `creating()` hook stamps automatically. If the trait was fully removed from `Transaction`, add a dedicated `creating()` hook back to the `Transaction` model for stamping only (without the read scope):
>
> ```php
> // In Transaction model's boot() method:
> static::creating(function ($model) {
>     if (empty($model->branch_id)) {
>         $model->branch_id = BranchContext::actingBranchId();
>     }
> });
> ```

- [ ] **Step 5: Convert `SavingsTransferController` raw inserts to Eloquent**

In `app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php`:

1. Remove `$branchId = BranchContext::currentBranchId();` at line ~92.
2. Find the two `DB::table('transactions')->insert([...])` calls at lines ~115 and ~133. Convert each to `Transaction::create([...])`.
3. Remove `'branch_id' => $branchId` from both calls — the model's `creating()` hook stamps it.
4. Add import at top if missing: `use App\Tenant\Modules\Transactions\Models\Transaction;`

- [ ] **Step 6: Run tests**

```bash
php artisan test
```
Expected: All pass. If a `Transaction` test fails with wrong `branch_id`, check the Transaction model's `creating()` hook is active (see note in Step 4).

- [ ] **Step 7: Commit**

```bash
git add app/Tenant/Http/Controllers/Api/V1/DashboardController.php \
        app/Tenant/Http/Controllers/Api/V1/TenantStaffController.php \
        app/Http/Globals/GlobalHelpers.php \
        app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php \
        app/Tenant/Http/Controllers/Api/V1/SavingsTransferController.php
git commit -m "feat(branch): dashboard opt-out, staff index opt-out, consolidate all stamping to trait"
```

---

## Task 5: Add `branch_scope` to roles table

**Spec step:** Step 6
**Files:**
- Create: `database/migrations/tenant/2026_03_24_000001_add_branch_scope_to_roles_table.php`

> Note: This migration must run before the `scopeFor()` DB lookup fully activates. The lookup already has a safe `SCOPE_SELF` fallback for the period before migration runs (added in Task 1).

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('tenant')->hasTable('roles')) {
            return;
        }

        if (! Schema::connection('tenant')->hasColumn('roles', 'branch_scope')) {
            Schema::connection('tenant')->table('roles', function (Blueprint $table) {
                $table->enum('branch_scope', ['all', 'branch', 'self'])->default('self')->after('name');
            });
        }

        $scopeMap = [
            'all'    => ['ceo', 'cfo', 'admin', 'super_admin', 'super-admin', 'tenant_admin', 'tenant-admin'],
            'branch' => ['manager', 'branch_manager', 'branch-manager'],
            'self'   => ['teller', 'staff', 'accountant', 'loan_officer', 'cashier'],
        ];

        foreach ($scopeMap as $scope => $roleNames) {
            DB::connection('tenant')
                ->table('roles')
                ->whereIn('name', $roleNames)
                ->update(['branch_scope' => $scope]);
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasColumn('roles', 'branch_scope')) {
            Schema::connection('tenant')->table('roles', function (Blueprint $table) {
                $table->dropColumn('branch_scope');
            });
        }
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan tenants:migrate
```
Expected: No errors. Verify in tinker:
```bash
php artisan tinker
>>> DB::connection('tenant')->table('roles')->get(['name', 'branch_scope']);
```

- [ ] **Step 3: Write and run tests**

> Note: Tests for this task are written AFTER migration because the test inserts into `roles` with `branch_scope` — the column must exist. This is acceptable; the column is a prerequisite for the test.

Add to `tests/Tenant/BranchContextTest.php`:

```php
it('scopeFor returns SCOPE_ALL for ceo via DB branch_scope column', function () {
    DB::connection('tenant')->table('roles')->updateOrInsert(
        ['name' => 'ceo'],
        ['branch_scope' => 'all', 'created_at' => now(), 'updated_at' => now()]
    );

    $staff = Staff::factory()->make(['is_tenant_admin' => false, 'role' => 'ceo']);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_ALL);
});

it('scopeFor returns correct scope for renamed role without code change', function () {
    DB::connection('tenant')->table('roles')->updateOrInsert(
        ['name' => 'chief_executive'],
        ['branch_scope' => 'all', 'created_at' => now(), 'updated_at' => now()]
    );

    $staff = Staff::factory()->make(['is_tenant_admin' => false, 'role' => 'chief_executive']);
    expect(BranchContext::scopeFor($staff))->toBe(BranchContext::SCOPE_ALL);
});
```

```bash
php artisan test --filter=BranchContextTest
```
Expected: All PASS.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tenant/2026_03_24_000001_add_branch_scope_to_roles_table.php \
        tests/Tenant/BranchContextTest.php
git commit -m "feat(branch): add branch_scope to roles table, seed known roles"
```

---

## Task 6: Drop `branch_id` from non-business tables

**Spec step:** Step 7
**Files:**
- Create: `database/migrations/tenant/2026_03_24_000002_drop_branch_id_from_global_tables.php`

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'system_settings',
        'sacco_branding',
        'savings_product_charges',
        'loan_schedules',
        'transaction_reversals',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (
                Schema::connection('tenant')->hasTable($table) &&
                Schema::connection('tenant')->hasColumn($table, 'branch_id')
            ) {
                Schema::connection('tenant')->table($table, function (Blueprint $t) {
                    $t->dropConstrainedForeignId('branch_id');
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (
                Schema::connection('tenant')->hasTable($table) &&
                ! Schema::connection('tenant')->hasColumn($table, 'branch_id')
            ) {
                Schema::connection('tenant')->table($table, function (Blueprint $t) {
                    $t->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                });
            }
        }
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan tenants:migrate
```
Expected: No errors.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test
```
Expected: All pass.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tenant/2026_03_24_000002_drop_branch_id_from_global_tables.php
git commit -m "feat(branch): drop branch_id from tenant-global config tables"
```

---

## Task 7: Add `staff_branch_access` pivot table

**Spec step:** Step 8
**Files:**
- Create: `database/migrations/tenant/2026_03_24_000003_create_staff_branch_access_table.php`
- Modify: `app/Support/BranchContext.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('staff_branch_access')) {
            return;
        }

        Schema::connection('tenant')->create('staff_branch_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['staff_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('staff_branch_access');
    }
};
```

- [ ] **Step 2: Update `allowedBranchIds()` in BranchContext to check pivot first**

Replace the `allowedBranchIds()` method:

```php
public static function allowedBranchIds(): ?array
{
    $user = Auth::user();
    if (! $user instanceof Staff) {
        return null;
    }

    if (static::scopeFor($user) === self::SCOPE_ALL) {
        return null;
    }

    // Multi-branch: check pivot table first
    if (Schema::connection('tenant')->hasTable('staff_branch_access')) {
        $pivotIds = DB::connection('tenant')
            ->table('staff_branch_access')
            ->where('staff_id', $user->id)
            ->pluck('branch_id')
            ->map(fn ($id) => (int) $id)
            ->toArray();

        if (! empty($pivotIds)) {
            return $pivotIds;
        }
    }

    // Fallback: single branch_id on staff record
    return $user->branch_id ? [(int) $user->branch_id] : [];
}
```

Also update `availableBranchesFor()` to use `allowedBranchIds()` internally (DRY):

```php
public static function availableBranchesFor(Staff $user): array
{
    if (! Schema::connection('tenant')->hasTable('branches')) {
        return [];
    }

    $hasSystemType = Schema::connection('tenant')->hasColumn('branches', 'system_type');
    $columns = array_values(array_filter(['id', 'name', 'code', $hasSystemType ? 'system_type' : null, 'is_active']));

    $query = DB::connection('tenant')
        ->table('branches')
        ->whereNull('deleted_at')
        ->orderBy('name')
        ->select($columns);

    if (static::scopeFor($user) !== self::SCOPE_ALL) {
        $allowedIds = static::allowedBranchIds();
        if (empty($allowedIds)) {
            return [];
        }
        $query->whereIn('id', $allowedIds);
    }

    return $query->get()->map(fn ($branch) => (array) $branch)->all();
}
```

- [ ] **Step 3: Write and run test**

Add to `tests/Tenant/BranchIsolationTest.php`:

```php
it('allowedBranchIds returns pivot branches when staff_branch_access entries exist', function () {
    $staff = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'manager']);

    DB::connection('tenant')->table('staff_branch_access')->insert([
        ['staff_id' => $staff->id, 'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
        ['staff_id' => $staff->id, 'branch_id' => 3, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($staff, 'sanctum');
    expect(BranchContext::allowedBranchIds())->toBe([2, 3]);
});
```

```bash
php artisan tenants:migrate && php artisan test --filter=BranchIsolationTest
```
Expected: All PASS.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/tenant/2026_03_24_000003_create_staff_branch_access_table.php \
        app/Support/BranchContext.php \
        tests/Tenant/BranchIsolationTest.php
git commit -m "feat(branch): add staff_branch_access pivot, update allowedBranchIds with pivot lookup"
```

---

## Task 8: Add composite indexes

**Spec step:** Step 9
**Files:**
- Create: `database/migrations/tenant/2026_03_24_000004_add_branch_reporting_indexes.php`

- [ ] **Step 1: Create migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('tenant')->hasTable('transactions')) {
            Schema::connection('tenant')->table('transactions', function (Blueprint $table) {
                $table->index(['branch_id', 'transaction_date'], 'idx_txn_branch_date');
                $table->index(['branch_id', 'type'],             'idx_txn_branch_type');
                $table->index(['created_by', 'transaction_date'], 'idx_txn_actor_date');
            });
        }

        if (Schema::connection('tenant')->hasTable('savings_accounts')) {
            Schema::connection('tenant')->table('savings_accounts', function (Blueprint $table) {
                $table->index(['branch_id', 'status'], 'idx_sa_branch_status');
            });
        }

        if (Schema::connection('tenant')->hasTable('loans')) {
            Schema::connection('tenant')->table('loans', function (Blueprint $table) {
                $table->index(['branch_id', 'status'], 'idx_loan_branch_status');
            });
        }

        if (Schema::connection('tenant')->hasTable('members')) {
            Schema::connection('tenant')->table('members', function (Blueprint $table) {
                $table->index('branch_id', 'idx_members_branch');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('tenant')->hasTable('transactions')) {
            Schema::connection('tenant')->table('transactions', function ($t) {
                $t->dropIndex('idx_txn_branch_date');
                $t->dropIndex('idx_txn_branch_type');
                $t->dropIndex('idx_txn_actor_date');
            });
        }
        if (Schema::connection('tenant')->hasTable('savings_accounts')) {
            Schema::connection('tenant')->table('savings_accounts', fn ($t) => $t->dropIndex('idx_sa_branch_status'));
        }
        if (Schema::connection('tenant')->hasTable('loans')) {
            Schema::connection('tenant')->table('loans', fn ($t) => $t->dropIndex('idx_loan_branch_status'));
        }
        if (Schema::connection('tenant')->hasTable('members')) {
            Schema::connection('tenant')->table('members', fn ($t) => $t->dropIndex('idx_members_branch'));
        }
    }
};
```

- [ ] **Step 2: Run migration**

```bash
php artisan tenants:migrate
```
Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/tenant/2026_03_24_000004_add_branch_reporting_indexes.php
git commit -m "feat(branch): add composite branch+date indexes for report query performance"
```

---

## Task 9: Frontend — Add `branchStore` Pinia store

**Spec step:** Step 10
**Files:**
- Create: `src/stores/branchStore.ts`
- Modify: `src/stores/auth.ts`

> Before starting: confirm the `Branch` type is exported from `src/tenant/apis/branches/branchesApi.ts`. If not, define it inline in `branchStore.ts`.

- [ ] **Step 1: Verify the `Branch` type export**

```bash
grep "export.*Branch" /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026/src/tenant/apis/branches/branchesApi.ts
```
Expected: `export interface Branch { ... }`. If missing, add `export` to the interface.

- [ ] **Step 2: Create `src/stores/branchStore.ts`**

```typescript
import { defineStore } from 'pinia'
import type { Branch } from '@/tenant/apis/branches/branchesApi'

interface BranchContext {
  scope: 'all' | 'branch' | 'self'
  assigned_branch: Branch | null
  active_branch_id: number | null
  available_branches: Branch[]
  show_branch_filter: boolean
  can_access_multiple_branches: boolean
}

export const useBranchStore = defineStore('branch', {
  state: () => ({
    scope: 'self' as 'all' | 'branch' | 'self',
    activeBranchId: null as number | null,
    availableBranches: [] as Branch[],
    showBranchFilter: false,
  }),

  getters: {
    canSwitchBranch: (state): boolean => state.scope === 'all',
  },

  actions: {
    initFromAuth(ctx: BranchContext) {
      this.scope             = ctx.scope
      this.activeBranchId    = ctx.active_branch_id
      this.availableBranches = ctx.available_branches ?? []
      this.showBranchFilter  = ctx.show_branch_filter ?? false
    },

    setActiveBranch(id: number | null) {
      if (this.scope !== 'all') return // non-admins cannot switch
      this.activeBranchId = id
    },

    reset() {
      this.scope             = 'self'
      this.activeBranchId    = null
      this.availableBranches = []
      this.showBranchFilter  = false
    },
  },
})
```

- [ ] **Step 3: Call `initFromAuth` after login/me in `src/stores/auth.ts`**

Locate where `auth/me` or login response is processed and `user` is set. Add:

```typescript
import { useBranchStore } from '@/stores/branchStore'

// After user is set from auth response:
const branchStore = useBranchStore()
if (data.branch_context) {
  branchStore.initFromAuth(data.branch_context)
}
```

Also add `branchStore.reset()` inside the logout action.

- [ ] **Step 4: Verify in browser via Vue DevTools → Pinia → branch store**

Log in as admin: assert `scope === 'all'`, `canSwitchBranch === true`, `availableBranches` has entries.
Log in as teller: assert `scope === 'self'`, `canSwitchBranch === false`.

- [ ] **Step 5: Commit**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
git add src/stores/branchStore.ts src/stores/auth.ts src/tenant/apis/branches/branchesApi.ts
git commit -m "feat(branch): add branchStore Pinia store, init from auth/me branch_context"
```

---

## Task 10: Frontend — Wire `branchStore` to list pages

**Spec step:** Step 11
**Files:**
- Modify: `src/tenant/modules/members/pages/Members.vue`
- Modify: `src/tenant/modules/settings/pages/StaffList.vue`
- Modify: savings accounts, transactions, loans list pages (locate exact paths)

### Pattern — apply to each list page

```typescript
import { watch } from 'vue'
import { useBranchStore } from '@/stores/branchStore'

const branchStore = useBranchStore()

// Re-fetch when admin switches branch
watch(() => branchStore.activeBranchId, () => fetchList())

async function fetchList() {
  const params: Record<string, unknown> = {}
  if (branchStore.activeBranchId !== null) {
    params.branch_id = branchStore.activeBranchId
  }
  await store.fetchItems(params) // or direct API call
}
```

- [ ] **Step 1: Apply pattern to `Members.vue`**
- [ ] **Step 2: Apply pattern to `StaffList.vue`**
- [ ] **Step 3: Locate and apply pattern to savings accounts list page**
- [ ] **Step 4: Locate and apply pattern to transactions list page**
- [ ] **Step 5: Locate and apply pattern to loans list page**
- [ ] **Step 6: Run TypeScript check**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check
```
Expected: No type errors.

- [ ] **Step 7: Commit**

```bash
git add src/tenant/modules/
git commit -m "feat(branch): pass activeBranchId as branch_id param to all tenant list pages"
```

---

## Task 11: Frontend — Add branch switcher to top nav

**Spec step:** Step 12
**Files:**
- Modify: top nav layout component

- [ ] **Step 1: Identify the top nav component**

```bash
ls /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026/src/tenant/layouts/
```
Read the file that contains the top navigation bar. Confirm the correct file before editing.

- [ ] **Step 2: Add branch switcher (visible only for `canSwitchBranch` users)**

In the `<script setup>`:
```typescript
import { useBranchStore } from '@/stores/branchStore'
const branchStore = useBranchStore()
```

In the template, inside the top nav:
```vue
<select
  v-if="branchStore.canSwitchBranch"
  :value="branchStore.activeBranchId ?? ''"
  @change="branchStore.setActiveBranch(($event.target as HTMLSelectElement).value ? Number(($event.target as HTMLSelectElement).value) : null)"
  class="rounded-lg border border-neutral-200 bg-white px-3 py-1.5 text-sm text-neutral-700 focus:outline-none dark:border-neutral-700 dark:bg-neutral-800 dark:text-white"
>
  <option value="">All Branches</option>
  <option
    v-for="branch in branchStore.availableBranches"
    :key="branch.id"
    :value="branch.id"
  >
    {{ branch.name }}
  </option>
</select>
```

- [ ] **Step 3: Verify in browser**

Log in as admin → branch switcher visible → select a branch → list pages re-fetch.
Log in as teller → branch switcher not visible.

- [ ] **Step 4: Run TypeScript check**

```bash
pnpm type-check
```

- [ ] **Step 5: Commit**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
git add src/tenant/layouts/
git commit -m "feat(branch): add branch switcher to top nav for SCOPE_ALL users"
```

---

## Task 12: Complete isolation test suite

**Spec step:** Step 13
**Files:**
- Modify: `tests/Tenant/BranchIsolationTest.php`

- [ ] **Step 1: Add remaining test cases**

```php
// Auto-stamping
it('creating a member as branch staff auto-stamps correct branch_id', function () {
    $staff = Staff::factory()->create(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');

    // Ensure Member::create satisfies all non-nullable columns (adjust fields to match your schema)
    $member = Member::create(['name' => 'Test Member', 'email' => 'test@example.com']);

    expect($member->branch_id)->toBe(5);
});

// Request body cannot override acting branch
it('branch_id in request body cannot override acting branch on write', function () {
    $staff = Staff::factory()->create(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    request()->merge(['branch_id' => 99]); // attacker injects different branch

    $member = Member::create(['name' => 'Test Member', 'email' => 'test@example.com']);
    expect($member->branch_id)->toBe(5);
});

// X-Acting-Branch-Id ignored for non-admin
it('X-Acting-Branch-Id header is ignored for non-SCOPE_ALL users', function () {
    $staff = Staff::factory()->create(['branch_id' => 5, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');
    $this->withHeaders(['X-Acting-Branch-Id' => '99']);

    expect(BranchContext::actingBranchId())->toBe(5);
});

// Query-builder enforces same rules as Eloquent
it('BranchQuery and BranchReadScope enforce identical restrictions for branch staff', function () {
    DB::connection('tenant')->table('members')->insert([
        ['name' => 'Alice', 'branch_id' => 1, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Bob',   'branch_id' => 2, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $staff = Staff::factory()->make(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $this->actingAs($staff, 'sanctum');

    // Eloquent path
    $eloquentCount = Member::count();

    // Query-builder path
    $qbQuery = DB::connection('tenant')->table('members');
    BranchQuery::scopeByBranch($qbQuery);
    $qbCount = $qbQuery->count();

    expect($eloquentCount)->toBe($qbCount)->toBe(1);
});

// Cross-branch transfer blocked via actor scoping on transactions
it('teller sees only own transactions via BranchQuery scopeByActor', function () {
    $teller1 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);
    $teller2 = Staff::factory()->create(['branch_id' => 1, 'is_tenant_admin' => false, 'role' => 'teller']);

    DB::connection('tenant')->table('transactions')->insert([
        ['amount' => 500,  'branch_id' => 1, 'created_by' => $teller1->id, 'type' => 'deposit', 'created_at' => now(), 'updated_at' => now()],
        ['amount' => 1000, 'branch_id' => 1, 'created_by' => $teller2->id, 'type' => 'deposit', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->actingAs($teller1, 'sanctum');

    $query = DB::connection('tenant')->table('transactions');
    BranchQuery::scopeByActor($query);

    expect($query->count())->toBe(1);
    expect((int) $query->first()->amount)->toBe(500);
});
```

- [ ] **Step 2: Run full isolation suite**

```bash
php artisan test --filter=BranchIsolationTest
```
Expected: All tests PASS.

- [ ] **Step 3: Run full test suite**

```bash
php artisan test
```
Expected: All tests PASS — zero regressions.

- [ ] **Step 4: Commit**

```bash
git add tests/Tenant/BranchIsolationTest.php
git commit -m "test(branch): complete isolation test suite — all cases passing"
```

---

## Final Verification Checklist

Before declaring the feature complete, verify each success criterion from the spec:

- [ ] Teller hitting transaction/activity endpoints only receives their own records
- [ ] Teller hitting branch-owned master-data endpoints never sees records outside their allowed branch set
- [ ] Branch manager sees only their branch's data across all pages
- [ ] Admin sees all branches by default, can filter via branch switcher in top nav
- [ ] Creating any branch-owned record auto-stamps correct `branch_id` via trait `creating()` hook
- [ ] No `branch_id` stamping logic exists outside `BelongsToAuthenticatedBranch` trait's `creating()` hook (or Transaction model's dedicated hook if trait was fully removed)
- [ ] `currentBranchId()` no longer exists in `BranchContext`
- [ ] Role-to-scope mapping survives a role rename without code changes
- [ ] Query-builder endpoints enforce the same rules as Eloquent endpoints (`BranchQuery` equivalence test passes)
- [ ] Non-privileged users cannot change acting branch via request body, query param, or `X-Acting-Branch-Id` header
- [ ] `system_settings`, `sacco_branding`, `loan_schedules`, `transaction_reversals`, `savings_product_charges` have no `branch_id` column
- [ ] All 12+ isolation tests pass
- [ ] Full test suite passes with zero regressions
