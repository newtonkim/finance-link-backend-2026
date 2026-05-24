# General Charges → Accounting Integration Design

**Date:** 2026-05-07  
**Author:** Newton Kim  
**Status:** Approved  

---

## 1. Problem Statement

`general_charges` defines fee rules (amount, type, credit_account_id, debit_account_id) but those rules are disconnected from the actual accounting pipeline:

1. `savings_product_charges` has no `general_charge_id` FK — savings fees are applied arbitrarily without resolving to a charge rule.
2. `SavingsJournalService::postCharge()` hardcodes GL code `4230` instead of reading `general_charge.credit_account_id`.
3. `loan_charges` does link to `general_charges` via `charge_id`, but that link is not used to drive journal entries — the JE for a loan charge is posted manually or skipped.
4. `member_charges` tracks per-member applied charges but does not enforce double-entry.

The goal is a clean, auditable pipeline:

```
Trigger (deposit / loan event)
  → ChargeCalculatorService   (which fee? how much?)
  → ChargeApplicationService  (MemberCharge record, deduplication)
  → ChargeJournalService      (DR member liability / CR income GL from general_charge)
  → GlPostingEngine           (existing — posts JE to general_ledger)
```

---

## 2. Scope

### In scope
- Add `general_charge_id` FK to `savings_product_charges`
- Add `general_charge_id` FK to `loan_product_charges` (if missing)
- Create `ChargeJournalService` — posts double-entry JE for any charge
- Create `ChargeCalculatorService` — resolves applicable charge + amount for a savings/loan event
- Create `ChargeApplicationService` — orchestrates record + JE, idempotent
- Fix `SavingsJournalService::postCharge()` hardcoded GL 4230
- Wire `ChargeApplicationService` into deposit and loan disbursement flows

### Out of scope
- Charge reversal UI (tracked separately)
- Batch/bulk retroactive charge application
- Charge waiver workflow

---

## 3. Data Model

### 3.1 Existing tables (unchanged structure)

| Table | Relevant columns |
|---|---|
| `general_charges` | `id`, `credit_account_id` (GL COA), `debit_account_id` (GL COA), `amount`, `charge_type` (fixed/percentage), `interval_type`, `where_to_apply` |
| `savings_product_charges` | `id`, `savings_product_id`, `charge_amount`, `charge_type`, `interval_type` |
| `loan_product_charges` | `id`, `loan_product_id`, `charge_id` (→ general_charges) |
| `member_charges` | `id`, `member_id`, `general_charge_id`, `transaction_id`, `amount`, `status` |
| `journal_entries` | `id`, `reference`, `description`, `posted_by` |
| `journal_entry_lines` | `id`, `journal_entry_id`, `account_id`, `debit`, `credit` |
| `general_ledger` | `id`, `account_id`, `debit`, `credit`, `balance`, `transaction_id` |

### 3.2 Migrations required

**Migration 1:** Add `general_charge_id` to `savings_product_charges`
```sql
ALTER TABLE savings_product_charges
  ADD COLUMN general_charge_id BIGINT UNSIGNED NULL,
  ADD FOREIGN KEY (general_charge_id) REFERENCES general_charges(id) ON DELETE SET NULL;
```

**Migration 2:** Verify `loan_product_charges.charge_id` FK exists (add if missing)

### 3.3 COA requirements

The following GL codes must exist in `chart_of_accounts` for every tenant:

| Code | Name | Type |
|---|---|---|
| `2100` | Member Savings — Liability | Liability |
| `4100` | Service Fee Income | Revenue |
| `4200` | Loan Processing Fee Income | Revenue |

Verify at seed time. `ChargeJournalService` will throw if either account is missing.

---

## 4. Service Architecture

### 4.1 ChargeCalculatorService

**Location:** `app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php`  
**Interface:** `app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php`

**Responsibility:** Given a context (savings account ID, event type, amount), return the applicable `general_charge` and computed fee.

```php
interface ChargeCalculatorServiceInterface
{
    /** Returns ['charge' => GeneralCharge, 'fee' => float] or null if no charge applies */
    public function resolveForSavings(int $savingsAccountId, string $eventType, float $transactionAmount): ?array;

    public function resolveForLoan(int $loanApplicationId, string $eventType, float $loanAmount): ?array;
}
```

**Logic:**
1. Load `savings_accounts.product_id` → `savings_product_charges` where `interval_type = $eventType` AND `general_charge_id IS NOT NULL`
2. Load `general_charge` via `general_charge_id`
3. Compute fee: `fixed` → `general_charge.amount`; `percentage` → `transactionAmount * (general_charge.amount / 100)`
4. Return `['charge' => $gc, 'fee' => $fee]`

### 4.2 ChargeJournalService

**Location:** `app/Tenant/Modules/Charges/Services/ChargeJournalService.php`  
**Interface:** `app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php`

**Responsibility:** Post a double-entry JE for a resolved charge.

```php
interface ChargeJournalServiceInterface
{
    public function post(
        GeneralCharge $charge,
        float $fee,
        int $memberId,
        int $savingsAccountId,
        string $reference,
        int $postedBy
    ): JournalEntry;
}
```

**JE structure:**
```
DR  general_charge.debit_account_id   $fee   (member savings liability — money leaves member account)
CR  general_charge.credit_account_id  $fee   (fee income — money arrives in income account)
```

Uses existing `GlPostingEngine` to commit both sides atomically.

### 4.3 ChargeApplicationService

**Location:** `app/Tenant/Modules/Charges/Services/ChargeApplicationService.php`  
**Interface:** `app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php`

**Responsibility:** Orchestrate one complete charge application: calculate → record → post JE.

```php
interface ChargeApplicationServiceInterface
{
    public function applyForSavingsEvent(
        int $savingsAccountId,
        string $eventType,
        float $transactionAmount,
        int $transactionId,
        int $actorId
    ): ?MemberCharge;
}
```

**Logic:**
1. Call `ChargeCalculatorService::resolveForSavings()`
2. If null → return null (no charge for this event type)
3. Check `member_charges` for duplicate: same `general_charge_id` + `transaction_id` → return existing (idempotent)
4. `DB::transaction()`:
   a. `MemberCharge::create([...])` with `status = 'applied'`
   b. `ChargeJournalService::post(...)` 
   c. Debit `savings_accounts.balance` by `$fee`
5. Return `MemberCharge`

---

## 5. Wiring Into Existing Services

### 5.1 SavingsJournalService (fix hardcoded GL)

**File:** `app/Tenant/Modules/Savings/Services/SavingsJournalService.php`  
**Change:** Remove hardcoded `4230` in `postCharge()`. Instead, call `ChargeApplicationService::applyForSavingsEvent()` — this replaces the old `postCharge()` method entirely.

### 5.2 Deposit flow

**File:** `app/Tenant/Modules/Savings/Services/SavingsTransactionService.php` (or equivalent)  
After posting the deposit JE, call:
```php
$this->chargeApplicationService->applyForSavingsEvent(
    $savingsAccountId, 'deposit', $amount, $transactionId, $actorId
);
```

### 5.3 Loan disbursement flow

**File:** `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php`  
After disbursement JE, call:
```php
$this->chargeApplicationService->applyForLoanEvent(
    $loanApplicationId, 'disbursement', $loanAmount, $transactionId, $actorId
);
```

---

## 6. Error Handling

- Missing COA account → `ChargeJournalService` throws `\DomainException("GL account {$id} not found for charge {$charge->id}")`
- Duplicate charge (same transaction) → `ChargeApplicationService` returns existing `MemberCharge` silently (idempotent, not an error)
- Negative fee (bad data) → `ChargeCalculatorService` throws `\InvalidArgumentException`
- All DB writes inside `DB::transaction()` — partial failure rolls back completely

---

## 7. Testing Plan

| Test | Type | Assertion |
|---|---|---|
| `ChargeCalculatorServiceTest` | Unit | Fixed fee → exact amount; Percentage fee → correct calculation; No matching charge → null |
| `ChargeJournalServiceTest` | Integration | JE created with correct DR/CR; GL balances updated; Wrong account throws |
| `ChargeApplicationServiceTest` | Integration | Duplicate call returns same MemberCharge; balance debited; JE count = 1 |
| `SavingsDepositChargeTest` | Feature | POST /savings/deposit → member_charges row created + JE row created |

---

## 8. File Manifest

### Create
- `database/migrations/tenant/2026_05_07_add_general_charge_id_to_savings_product_charges.php`
- `database/migrations/tenant/2026_05_07_add_general_charge_id_to_loan_product_charges.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeCalculatorServiceInterface.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeJournalServiceInterface.php`
- `app/Tenant/Modules/Charges/Contracts/ChargeApplicationServiceInterface.php`
- `app/Tenant/Modules/Charges/Services/ChargeCalculatorService.php`
- `app/Tenant/Modules/Charges/Services/ChargeJournalService.php`
- `app/Tenant/Modules/Charges/Services/ChargeApplicationService.php`
- `app/Providers/ChargeServiceProvider.php`

### Modify
- `app/Tenant/Modules/Savings/Services/SavingsJournalService.php` — remove hardcoded GL 4230
- `app/Tenant/Modules/Savings/Services/SavingsTransactionService.php` — wire `ChargeApplicationService`
- `app/Tenant/Modules/Loans/Services/LoanDisbursementService.php` — wire `ChargeApplicationService`
- `config/app.php` — register `ChargeServiceProvider`

---

## 9. Rollout Sequence

1. Run migrations (adds nullable FK — fully backward-compatible)
2. Verify COA has required accounts on all tenants
3. Deploy new services (no side effects until wired)
4. Wire `SavingsTransactionService` + `LoanDisbursementService`
5. Remove old `SavingsJournalService::postCharge()` hardcoded block
6. Run feature tests on staging
7. Ship
