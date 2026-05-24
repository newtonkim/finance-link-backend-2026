# Branch Enforcement Design Spec
**Date:** 2026-03-24
**Project:** Mfuko Pro — Multi-Tenant SACCO Platform
**Scope:** Backend enforcement + Frontend branch isolation
**Principles:** DRY, SOLID, no over-engineering

---

## 1. Problem Statement

Branch access is not enforced system-wide. `BranchContext` exists and works correctly in the reporting path, but no other part of the system (dashboards, member lists, savings accounts, transactions) applies branch filtering consistently. The result is that a teller or branch manager can hit tenant API endpoints and see or act on data outside their intended isolation boundary.

Ten concrete gaps were identified:

| # | Finding | Severity |
|---|---------|----------|
| F1 | Branch reads not enforced outside ReportsController | Critical |
| F2 | Role-to-scope mapping hardcoded as string array in BranchContext | High |
| F3 | "Acting branch" (writes) and "filter branch" (reads) conflated on same request field | High |
| F4 | Branch stamping scattered across trait, GlobalHelpers, and controllers | Medium |
| F5 | branch_id added to non-business tables (system_settings, sacco_branding, loan_schedules, etc.) | Medium |
| F6 | No multi-branch assignment model for regional managers | Medium |
| F7 | Frontend auth signal (branch_context) returned by API but never consumed | High |
| F8 | No composite indexes on transactions.branch_id + transaction_date | Medium |
| F9 | Teller "self" isolation is not the same as branch isolation | Critical |
| F10 | Global Eloquent scopes alone will not protect query-builder / raw SQL paths | Critical |

---

## 2. Design Principles

- **DRY** — Branch logic lives in one place per concern. One service resolves context. One Eloquent scope handles model reads. One query helper handles query-builder reads. One store distributes state to the frontend.
- **Single Responsibility** — `BranchContext` resolves scope. `BranchReadScope` enforces Eloquent reads. `BranchQuery` enforces query-builder reads. `BelongsToAuthenticatedBranch` stamps writes. Services apply business rules. Controllers stay thin.
- **Open/Closed** — Adding a new model to branch enforcement = add one trait. Adding a new role scope = update the DB record, no code change.
- **No over-engineering** — No separate ACL package, no custom middleware chain, no event sourcing. Fix the identified findings with the minimum necessary code.

---

## 3. Architecture Overview

```text
Request
  └── BranchContext resolver (Step 1)
        ├── resolves user scope, acting branch, allowed branches
        ├── BranchReadScope (Eloquent global scope)
        │     └── auto-filters SELECTs on scoped models
        ├── BranchQuery helper
        │     └── filters DB::table(...) and report queries
        ├── BelongsToAuthenticatedBranch trait
        │     └── auto-stamps branch_id on INSERT
        └── Service layer
              └── business rules (cross-branch ops, approvals)

Frontend
  └── branchStore (Pinia)
        ├── initialized from auth/me → branch_context
        ├── branch switcher (admin/CEO only)
        └── passed as query param to list/report pages only
```

---

## 4. Implementation Plan (13 Steps)

Steps must be implemented and user-verified one at a time before proceeding to the next.

---

### Step 1 — Separate `actingBranchId()` from `filterBranchId()` in BranchContext
**Fixes:** F3, F9  
**Files:** `app/Support/BranchContext.php`

Split the single branch resolver into distinct, non-overlapping responsibilities:

- `actingBranchId()` — used only when stamping new records. Always reads from `staff.branch_id` in the database, never from request input. Admins with `SCOPE_ALL` may override via an explicit acting-branch transport.
- `filterBranchId(Request $request)` — used only for read filtering and reports. Reads `request()->input('branch_id')` but only honours it for `SCOPE_ALL` users.
- `viewerScope()` — resolves one of `all`, `branch`, or `self`.
- `allowedBranchIds()` — resolves the branches the user is allowed to see.

Rules:

- `SCOPE_ALL` = CEO/CFO/admin-style roles
- `SCOPE_BRANCH` = branch managers / branch-scoped supervisors
- `SCOPE_SELF` = tellers and other self-scoped roles

Important:

- `SCOPE_SELF` does **not** mean "same as branch scope". For transactions and activity logs it means "my own records only".
- `actingBranchId()` and `filterBranchId()` must never read from the same input field.

Acting-branch transport:

- Browser UI: top-nav branch switcher stores acting branch in authenticated session or SPA auth store
- API clients: explicit `X-Acting-Branch-Id` header
- Allowed only for `SCOPE_ALL` users
- If not set, acting branch falls back to `staff.branch_id`
- `branch_id` query/body params are **never** treated as acting-branch selectors

Remove the original `currentBranchId()` method entirely once both consumers are updated.

---

### Step 2 — Create `BranchReadScope` and register in trait
**Fixes:** F1, F9  
**Files:** `app/Models/Scopes/BranchReadScope.php`, `app/Models/Concerns/BelongsToAuthenticatedBranch.php`

A single Laravel `Scope` class implementing `Illuminate\Database\Eloquent\Scope`. Applied automatically to all models that use the `BelongsToAuthenticatedBranch` trait.

Rules:

- If authenticated user is not a `Staff` instance → no filter (central admin passes through)
- If `SCOPE_ALL` → no filter
- If `SCOPE_BRANCH` → `WHERE {table}.branch_id IN allowedBranchIds()`
- If `SCOPE_SELF`:
  - branch-owned master tables → `WHERE {table}.branch_id IN allowedBranchIds()`
  - actor-owned activity tables → `WHERE {table}.created_by = auth()->id()` (or equivalent actor column)
  - actor-owned tables may additionally retain branch restriction if branch_id exists, but actor restriction is mandatory

Definitions:

- branch-owned master tables: `members`, `staff`, `savings_accounts`, `loans`, `shares`
- actor-owned activity tables: `transactions`, approval logs, reversals, cash movements
- derived tables: inherit visibility from parent entity and should not invent an independent branch rule

Controllers that need cross-branch aggregation must explicitly opt out.

---

### Step 3 — Create `BranchQuery` helper for query-builder enforcement
**Fixes:** F1, F9, F10  
**Files:** `app/Support/BranchQuery.php`, service-layer queries, report/dashboard controllers

This codebase uses a mix of Eloquent and `DB::table(...)`. A global Eloquent scope alone is not sufficient.

`BranchQuery` must expose reusable helpers such as:

- `scopeByBranch($query, $branchColumn = 'branch_id')`
- `scopeByActor($query, $actorColumn = 'created_by')`
- `scopeByVisibility($query, $branchColumn = 'branch_id', $actorColumn = null)`

Rules:

- `SCOPE_ALL` → no restriction unless filtered
- `SCOPE_BRANCH` → restrict by allowed branches
- `SCOPE_SELF`:
  - transactions/activity → restrict by actor (`created_by = auth()->id()`)
  - branch-owned master tables → restrict by allowed branches

This helper is mandatory for dashboard, reports, and any service/controller still using query builder.

---

### Step 4 — Add explicit opt-out / cross-branch aggregation handling to Dashboard and Reports
**Fixes:** F1, F10  
**Files:** `app/Tenant/Http/Controllers/Api/V1/DashboardController.php`, `app/Tenant/Http/Controllers/Api/V1/ReportsController.php`

Any query that intentionally aggregates across all branches must explicitly opt out.

- Eloquent queries use `->withoutGlobalScope(BranchReadScope::class)`
- Query-builder queries use a documented bypass or explicit all-branch branch-query mode

This makes cross-branch access visible in code and auditable.

---

### Step 5 — Consolidate branch stamping to one location
**Fixes:** F4  
**Files:** `app/Http/Globals/GlobalHelpers.php`, `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php`, `app/Models/Concerns/BelongsToAuthenticatedBranch.php`

Remove branch_id stamping from `GlobalHelpers.php` and from controller-level transaction writes. The `BelongsToAuthenticatedBranch::creating()` hook using `actingBranchId()` is the single canonical stamp location.

Verify the trait is applied to all 6 core models:

- `Staff`
- `Member`
- `SavingsAccount`
- `Transaction`
- `Loan`
- `GeneralCharge`

If any writes still use `DB::table()->insert(...)`, either:

- refactor them to Eloquent creation, or
- route them through one shared write helper that stamps `actingBranchId()`

Compliance rule:

- Any direct `DB::table()->insert(...)` touching a branch-owned table is non-compliant unless it goes through the canonical branch-stamping helper.

---

### Step 6 — Make role-to-scope data-driven
**Fixes:** F2  
**Files:** `database/migrations/tenant/`, `app/Support/BranchContext.php`, tenant seeders

Add a `branch_scope` enum column (`all`, `branch`, `self`) to the tenant role definition, or create a lookup table seeded during tenant provisioning.

Replace the hardcoded string array in `BranchContext::scopeFor()` with a DB lookup with a safe default of `SCOPE_SELF`.

---

### Step 7 — Drop branch_id from non-business tables
**Fixes:** F5  
**Files:** New tenant migration

Create a migration that removes `branch_id` from tables that are tenant-global config, not branch-owned business facts:

- `system_settings`
- `sacco_branding`
- `savings_product_charges`
- `loan_schedules`
- `transaction_reversals`

Keep `branch_id` on all operational/business-fact tables.

---

### Step 8 — Add `staff_branch_access` pivot table
**Fixes:** F6  
**Files:** New tenant migration, `app/Support/BranchContext.php`

A `staff_branch_access` table with `(staff_id, branch_id)` unique composite key allows modelling regional managers who need access to a specific subset of branches.

`BranchContext::availableBranchesFor()` updated with priority:

1. `SCOPE_ALL` → return all branches
2. Has rows in `staff_branch_access` → return those branches
3. Fallback → return `[staff.branch_id]`

Existing single-branch staff are unaffected. Multi-branch is additive and opt-in.

---

### Step 9 — Add composite indexes migration
**Fixes:** F8  
**Files:** New tenant migration

Composite indexes on the columns most heavily used in branch-scoped report queries:

- `transactions(branch_id, transaction_date)`
- `transactions(branch_id, type)`
- `transactions(created_by, transaction_date)`
- `savings_accounts(branch_id, status)`
- `loans(branch_id, status)`
- `members(branch_id)`

Add verification queries after migration to confirm the planner uses the new indexes on large-tenant datasets.

---

### Step 10 — Frontend: Add `branchStore` Pinia store
**Fixes:** F7  
**Files:** `src/stores/branchStore.ts`

A single Pinia store initialized from the `branch_context` object already returned by `auth/me`. Holds:

- `scope` — user's access scope (`all` | `branch` | `self`)
- `activeBranchId` — currently selected branch for filtering (`null = all`, only settable by `SCOPE_ALL` users)
- `availableBranches` — list of branches the user can access
- `showBranchFilter` — derived: true when `scope !== 'self'`
- `canSwitchBranch` — derived: true when `scope === 'all'`

Actions:

- `initFromAuth(branchContext)` — called once on login/page load
- `setActiveBranch(id | null)` — for the admin branch switcher

---

### Step 11 — Frontend: Wire `branchStore` to all list and report pages
**Fixes:** F7  
**Files:** All tenant list pages, reports pages, and branch-switch-aware screens

All list pages (Members, Staff, Savings Accounts, Transactions, Loans, Reports) pass `branchStore.activeBranchId` as a `branch_id` query param to their API calls. Backend enforcement remains authoritative.

Forms should **not** expose branch selection by default.

Rules:

- normal branch staff: no visible branch selector on transactional forms
- branch managers: branch filter visible on list/report pages, but forms still use implicit acting branch
- `SCOPE_ALL` users: branch switcher selects acting/filter branch at navigation/session level, not per form field

Only explicitly administrative setup screens may expose a branch selector when assigning ownership, for example staff branch assignment.

Frontend rule:

- `branch_id` on forms is metadata shown only where the business object itself is being assigned to a branch
- transactional forms must use acting branch implicitly and must not expose branch selection

---

### Step 12 — Frontend: Add branch switcher to top nav
**Fixes:** F7  
**Files:** `src/tenant/layouts/TenantTopBar.vue` (or equivalent nav component)

A dropdown component visible only when `branchStore.canSwitchBranch === true`. Options: "All Branches" + each branch from `availableBranches`. Selecting a branch calls `branchStore.setActiveBranch(id)` which reactively re-fetches list/report pages.

---

### Step 13 — Write branch isolation tests
**Fixes:** All  
**Files:** `tests/Tenant/BranchIsolationTest.php`

Pest tests covering:

- Teller cannot read branch-owned records from another branch
- Teller sees only their own transactions/activity records
- Branch manager sees only their branch records
- Admin sees all records
- Creating a record as branch staff auto-stamps correct branch_id
- Cross-branch transfer attempt is blocked
- Role scope lookup falls back to `SCOPE_SELF` for unknown roles
- Query-builder endpoints are scoped the same as Eloquent endpoints
- `branch_id` request param cannot override acting branch on writes
- `X-Acting-Branch-Id` is ignored for non-`SCOPE_ALL` users

---

## 5. Files Touched Per Step

| Step | Backend Files | Frontend Files |
|------|--------------|----------------|
| 1 | `app/Support/BranchContext.php` | — |
| 2 | `app/Models/Scopes/BranchReadScope.php`, `app/Models/Concerns/BelongsToAuthenticatedBranch.php` | — |
| 3 | `app/Support/BranchQuery.php`, service-layer query builder code | — |
| 4 | `DashboardController.php`, `ReportsController.php` | — |
| 5 | `GlobalHelpers.php`, `SavingsAccountController.php`, trait/write helpers | — |
| 6 | New migration, `BranchContext.php`, tenant seeder | — |
| 7 | New migration | — |
| 8 | New migration, `BranchContext.php` | — |
| 9 | New migration | — |
| 10 | — | `src/stores/branchStore.ts` |
| 11 | — | All tenant list/report pages |
| 12 | — | Top nav component |
| 13 | `tests/Tenant/BranchIsolationTest.php` | — |

---

## 6. Table Classification Matrix

This matrix is normative. If a table is not classified, it must not be branch-enforced until classified.

| Table Group | Examples | Scope Type | Enforcement Mode | branch_id Required | Notes |
|------------|----------|------------|------------------|--------------------|-------|
| Tenant-global config | `system_settings`, `sacco_branding`, `roles`, `permissions` | tenant-global | none | No | not branch-owned |
| Branch-owned master data | `staff`, `members`, `savings_accounts`, `loans`, `shares` | branch-owned | branch scope | Yes | visible by branch access |
| Actor-owned activity data | `transactions`, approval logs, reversals | actor-owned | actor scope + optional branch scope | Yes | teller isolation depends on actor |
| Derived data | `loan_schedules`, reversal detail records | derived | inherit parent | Usually No | do not duplicate scope without reason |
| Cross-branch business ops | branch transfer records, consolidated reports | special-case | service/business rule | Contextual | explicit authorization required |

If a future table is added, assign it one of these categories before exposing it in tenant APIs.

---

## 7. Rollout Safeguards

- Introduce enforcement behind a feature flag per tenant or per environment where possible.
- Add denied-access audit logging for cross-branch attempts during rollout.
- Run backfill verification queries:
  - no branch-owned records remain with unexpected `NULL branch_id`
  - no orphaned `branch_id` values
- Add a temporary operational report showing records created without canonical stamping.
- Verify branch counts and totals before/after enabling enforcement on a tenant.

---

## 8. What This Does NOT Change

- The `branches` table schema remains the base source of branch master data
- Tenant provisioning still needs a default "Head Office" branch

This spec does **not** assume:

- all branch CRUD is already complete
- reports are already fully correct
- migrations alone are enough to guarantee isolation

---

## 9. Success Criteria

- [ ] A teller hitting transaction/activity endpoints only receives their own records
- [ ] A teller hitting branch-owned master-data endpoints never sees records outside their allowed branch set
- [ ] A branch manager sees only their branch's data across all pages
- [ ] An admin/CEO sees all branches by default, can filter to one branch via the branch switcher
- [ ] Creating any branch-owned record auto-stamps the correct branch_id from the acting branch
- [ ] No branch stamping logic exists outside the canonical write path
- [ ] Role-to-scope mapping survives a role rename without code changes
- [ ] Query-builder endpoints and Eloquent endpoints enforce the same branch isolation rules
- [ ] Non-privileged users cannot change acting branch via body/query/header input
- [ ] Each branch-owned table is classified and enforced according to the matrix in Section 6
- [ ] Rollout verification reports show no unexpected null/orphan branch references
- [ ] All 13 isolation tests pass
