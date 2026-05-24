# Loan Activity Audit Trail — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a complete loan activity audit trail for the active-loan detail page (`/tenant/loans/:id` → "Loan Activities" tab) that streams a unified chronological feed of every significant event on the life of a loan — from disbursement through every repayment, status change, and penalty assessment.

**Architecture:** A new `LoanActivityService` (backend) mirrors the existing `LoanTimelineService` (which handles applications only) but operates on the active `Loan` model. It aggregates events from four existing data sources — the `loans` table, `loan_status_histories`, `loan_transactions`, and scheduled penalties — without requiring any new database tables. A new `GET /loans/{id}/activities` endpoint serves the event list. The frontend replaces the "coming soon" placeholder with the existing `LoanAuditTrail.vue` component (enhanced with new event types and richer per-event UI).

**Tech Stack:** Laravel 12 (PHP 8.2+), Pest, Vue 3 / TypeScript, Tailwind CSS, Lucide Vue, existing `LoanController` + `useLoanAccount` composable pattern.

---

## Current-State Inventory

Before touching any code, understand the pieces already in place:

| What exists | File | Role |
|---|---|---|
| Active-loan detail page | `src/tenant/modules/loans/pages/LoanAccountDetail.vue` | Renders tabs; "activities" tab shows "coming soon" placeholder at line 1547–1552 |
| Loan composable | `src/tenant/modules/loans/composables/useLoanAccount.ts` | Fetches loan, schedule, repayments on mount — no activities fetch |
| Loan API client | `src/tenant/apis/loans/loansApi.ts` | No `getActivities()` method exists yet |
| Audit Trail UI component | `src/tenant/modules/loans/components/LoanAuditTrail.vue` | Timeline renderer that accepts `TimelineEvent[]` — currently wired only to loan applications |
| Application timeline service | `app/Tenant/Modules/Loans/Services/LoanTimelineService.php` | Pattern to follow exactly |
| Application timeline contract | `app/Tenant/Modules/Loans/Contracts/LoanTimelineServiceInterface.php` | Shows the interface shape expected |
| Loan status history model | `app/Tenant/Modules/Loans/Models/LoanStatusHistory.php` | `loan_status_histories` table — from_status, to_status, changed_by, notes, changed_at |
| Loan controller | `app/Tenant/Http/Controllers/Api/V1/LoanController.php` | Already has `show`, `schedule`, `repayments`, `ledger` — add `activities` here |
| Routes | `routes/tenant_api.php` lines 132–141 | Add one line for the new endpoint |
| Service binding | `app/Providers/AppServiceProvider.php` lines 86–87 | Register new interface → implementation |

---

## Event Catalogue

The audit trail must cover these event types, in chronological order:

| Event type | DB source | Key fields |
|---|---|---|
| `disbursed` | `loans` (disbursed_at, disbursed_by, principal, net_disbursed_amount) | "Loan Disbursed — UGX X to member Y via method Z" |
| `status_change` | `loan_status_histories` (from_status, to_status, changed_by, notes, changed_at) | "Status: Active → Arrears" |
| `repayment` | `loan_transactions` (amount_paid, payment_date, payment_method, receipt_no, collected_by) | "Repayment Received — UGX X via Cash · Receipt #Y" |
| `penalty_assessed` | `loan_repayment_schedule` (penalty_due > 0, due_date, last_arrears_tier_id) | "Penalty Applied — UGX X on installment #N (N days overdue)" |

`penalty_assessed` has no dedicated log table — derive it from schedules where `penalty_due > 0`. Each such installment generates one event timestamped to `due_date` (the day the penalty was assessed).

---

## Data Shape — `LoanActivityEvent`

```
{
  type:        'disbursed' | 'status_change' | 'repayment' | 'penalty_assessed'
  title:       string          // short human label
  description: string          // one sentence
  amount:      string | null   // formatted money where relevant
  actor:       { id, name } | null
  notes:       string | null
  timestamp:   ISO-8601 datetime string
}
```

This shape is backward-compatible with the existing `TimelineEvent` in `LoanAuditTrail.vue` — add `amount` as an optional new field.

---

## File Map

### New files (create)

| File | Responsibility |
|---|---|
| `app/Tenant/Modules/Loans/Contracts/LoanActivityServiceInterface.php` | Single-method contract: `getActivity(Loan): Collection` |
| `app/Tenant/Modules/Loans/Services/LoanActivityService.php` | Aggregates all four event types into a sorted collection |

### Modified files (backend)

| File | Change |
|---|---|
| `app/Tenant/Http/Controllers/Api/V1/LoanController.php` | Add `activities(int $id): JsonResponse` method |
| `routes/tenant_api.php` | Add `Route::get('loans/{id}/activities', ...)` after line 138 |
| `app/Providers/AppServiceProvider.php` | Bind `LoanActivityServiceInterface` → `LoanActivityService` |

### Modified files (frontend)

| File | Change |
|---|---|
| `src/tenant/apis/loans/loansApi.ts` | Add `LoanActivityEvent` interface + `getActivities(id)` method |
| `src/tenant/modules/loans/composables/useLoanAccount.ts` | Add `activities` ref + `fetchActivities()` + fetch on mount |
| `src/tenant/modules/loans/components/LoanAuditTrail.vue` | Extend to handle new event types + amount display + icon set |
| `src/tenant/modules/loans/pages/LoanAccountDetail.vue` | Replace placeholder (lines 1545–1552) with `<LoanAuditTrail>` |

---

## Task 1 — Backend Contract

**File:** `app/Tenant/Modules/Loans/Contracts/LoanActivityServiceInterface.php`

Define a single-method interface modelled exactly on `LoanTimelineServiceInterface`. The method receives the loaded `Loan` model and returns an `Illuminate\Support\Collection` of event arrays. This interface is what `LoanController` will type-hint — it must never depend on the concrete service.

---

## Task 2 — Backend Service: `LoanActivityService`

**File:** `app/Tenant/Modules/Loans/Services/LoanActivityService.php`

Implement `LoanActivityServiceInterface`. Four private builder methods, one per event type, merged and sorted by timestamp ascending:

### 2a — `disbursedEvent(Loan $loan): array`
- Pull `disbursed_at`, `disbursed_by` staff relation, `principal`, `net_disbursed_amount`, `disbursement_method` from the `Loan` model.
- The `disbursedBy` relation is already defined on `Loan` (staff who clicked disburse).
- Title: `"Loan Disbursed"` / Description: `"UGX {principal} disbursed via {method}. Net received: UGX {net}."`
- Amount: formatted `net_disbursed_amount`.
- Timestamp: `disbursed_at`.

### 2b — `statusEvents(Loan $loan): Collection`
- Query `LoanStatusHistory::with('changedBy')->where('loan_id', $loan->id)->orderBy('changed_at')`.
- Reuse the `labelStatus()` helper pattern from `LoanTimelineService`.
- Title: `"Status: {from} → {to}"`.
- Amount: `null`.

### 2c — `repaymentEvents(Loan $loan): Collection`
- Query `$loan->repayments()->with('collectedBy')->where('reversal_flag', false)->orderBy('payment_date')`.
- `Loan::repayments()` is a `HasMany` already defined in the model → `LoanTransaction`.
- Title: `"Repayment Received"`. Description: `"UGX {amount} via {method}. Receipt: {receipt_no}."`.
- Amount: formatted `amount_paid`.
- Actor: `collected_by` staff.
- Timestamp: `payment_date` (cast it to datetime).

### 2d — `penaltyEvents(Loan $loan): Collection`
- Query `LoanSchedule::where('loan_id', $loan->id)->where('penalty_due', '>', 0)->orderBy('due_date')`.
- One event per installment row that has a penalty.
- Title: `"Penalty Assessed"`. Description: `"UGX {penalty_due} penalty applied on installment #{installment_no} ({days_overdue} days overdue)."`.
- Amount: formatted `penalty_due`.
- Actor: `null` (system-generated).
- Timestamp: `due_date` cast to midnight datetime.

### Merge & sort
Collect all four arrays/collections into one, call `->sortBy('timestamp')->values()` and return.

---

## Task 3 — Register Service Binding

**File:** `app/Providers/AppServiceProvider.php`

Add alongside the existing `LoanTimelineServiceInterface` binding (lines 86–87):

```
LoanActivityServiceInterface::class  →  LoanActivityService::class
```

---

## Task 4 — API Endpoint

**File:** `app/Tenant/Http/Controllers/Api/V1/LoanController.php`

Add `activities(int|string $id): JsonResponse`:
- Resolve the loan using the existing private `resolveLoan()` helper (same pattern as `schedule()` and `repayments()`).
- Load relations needed: `disbursedBy`, `repayments.collectedBy`, `statusHistories.changedBy`.
- Inject `LoanActivityServiceInterface` via constructor (already injected with `LoanPenaltyCalculatorServiceInterface` — follow same pattern).
- Call `$service->getActivity($loan)` and return `response()->json(['data' => $events])`.

**File:** `routes/tenant_api.php`

Add one line after line 138:
```
Route::get('loans/{id}/activities', [LoanController::class, 'activities']);
```

---

## Task 5 — Backend Tests (Pest)

**File:** `tests/Tenant/Loans/LoanActivityServiceTest.php`

Cover these cases:
- Given a disbursed loan with no status changes/repayments/penalties → returns one event of type `disbursed`.
- Given a loan with two repayments → returns three events total, sorted by timestamp.
- Given a loan with a schedule row where `penalty_due > 0` → penalty event present with correct amount and installment number.
- Given a loan with a status change → status event has correct `from` and `to` labels.
- Events are sorted chronologically across all types (a repayment before a status change appears first).

**File:** `tests/Tenant/Loans/LoanActivityEndpointTest.php`

- `GET /api/v1/tenant/loans/{id}/activities` returns 200 with `data` array.
- Unauthenticated request returns 401.
- Non-existent loan ID returns 404.

---

## Task 6 — Frontend: API Layer

**File:** `src/tenant/apis/loans/loansApi.ts`

**Add interface:**
```typescript
export interface LoanActivityEvent {
  type: 'disbursed' | 'status_change' | 'repayment' | 'penalty_assessed'
  title: string
  description: string
  amount: string | null
  actor: { id: number; name: string } | null
  notes: string | null
  timestamp: string
}
```

**Add method to `loansApi`:**
```typescript
getActivities(id: number) {
  return tenantClient.get<{ data: LoanActivityEvent[] }>(`/loans/${id}/activities`)
}
```

---

## Task 7 — Frontend: Composable

**File:** `src/tenant/modules/loans/composables/useLoanAccount.ts`

Changes:
1. Import `LoanActivityEvent` from `loansApi`.
2. Add `const activities = ref<LoanActivityEvent[]>([])`.
3. Add `async function fetchActivities()` that calls `loansApi.getActivities(resolvedLoanId)` and assigns to `activities.value`.
4. Include `fetchActivities()` inside both `load()` and `refresh()` parallel `Promise.all` calls.
5. Return `activities` and `fetchActivities` from the composable.

---

## Task 8 — Frontend: Enhance `LoanAuditTrail.vue`

**File:** `src/tenant/modules/loans/components/LoanAuditTrail.vue`

The component currently handles four types from loan applications (`created`, `status_change`, `document_uploaded`, `approval_vote`). It needs to handle the four loan activity types (`disbursed`, `status_change`, `repayment`, `penalty_assessed`).

**Prop change:** Accept `LoanActivityEvent[]` in addition to (or instead of) `TimelineEvent[]`. Make the prop type a union or a shared base interface. Simplest approach: define a union type `AuditEvent = TimelineEvent | LoanActivityEvent` and update the prop.

**Icon map (new types):**
| Event type | Lucide icon | Dot colour |
|---|---|---|
| `disbursed` | `Banknote` | Emerald green |
| `status_change` | `RefreshCw` | Amber |
| `repayment` | `ArrowDownLeft` | Blue |
| `penalty_assessed` | `AlertTriangle` | Red |

**New `amount` field display:** When `event.amount` is set, render it below the description as a highlighted amount badge (e.g. `"+ UGX 381,988.55"` in emerald for repayments, `"− UGX 5,000"` in red for penalties).

**Empty state:** Keep the existing "No events yet." message.

**UX refinement — visual hierarchy:**
- Each event row: timestamp top (muted xs), title bold, description muted sm, actor + amount on same row at bottom.
- Dot colour from the icon map above.
- The timeline left-border (current: `border-neutral-200`) should stay — it's clean and familiar.

---

## Task 9 — Frontend: Wire Into `LoanAccountDetail.vue`

**File:** `src/tenant/modules/loans/pages/LoanAccountDetail.vue`

1. Import `LoanAuditTrail` at the top of `<script setup>`.
2. Destructure `activities` from `useLoanAccount(loanId)`.
3. Replace lines 1545–1552 (the "coming soon" div) with:
   ```html
   <LoanAuditTrail :timeline="activities" :loading="loading" />
   ```
4. No other changes needed in this file.

---

## Task 10 — Frontend Verification

Manual verification steps before closing:
- Navigate to `tenant/loans/21` → "Loan Activities" tab.
- Verify disbursement event appears at the top with correct amount and date.
- Post a test repayment → refresh the tab → new repayment event appears.
- Check a loan that has overdue installments → penalty events are present with correct amounts.
- Verify events are sorted oldest → newest.
- Verify loading spinner shows while fetch is in progress.
- Test on a loan with zero events (fresh disbursement, no repayments yet) → "No events yet." shown.

---

## Architecture Decisions & Rationale

| Decision | Rationale |
|---|---|
| No new DB table | All four event types already have data sources. Adding an `audit_events` table is YAGNI until there's a business reason to store ad-hoc notes or manual entries. |
| Service not Observer | An Observer on `LoanTransaction` would be cleaner long-term but risks silent failures if the event bus misbehaves. The aggregation service reads existing data — it cannot fall behind or lose events. Migrate to events/observers in a later iteration if needed. |
| `penalty_assessed` derived from schedule | There is no `LoanPenaltyLog` table. The schedule's `penalty_due` field is the authoritative record. Deriving events from it means zero write-side changes. |
| Extend existing `LoanAuditTrail.vue` | Rewriting it from scratch would break the applications timeline (already wired in `LoanApplicationShow.vue`). Extending with a union type keeps both use-cases working. |
| `getActivities` loads on mount (not lazily) | The activities tab is one of six — loading it upfront avoids a spinner when the user clicks the tab. The payload is small (dozens of events). Revisit if loans accumulate thousands of transactions. |
| Endpoint paginates? No — not yet | The typical loan term is 12–60 months = at most ~120 events. Not worth pagination complexity now. |
