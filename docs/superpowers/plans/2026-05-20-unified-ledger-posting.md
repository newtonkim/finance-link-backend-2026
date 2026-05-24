# Unified Mandatory Ledger Posting — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Note on "no code":** Per request, this plan describes *what* each step does, the exact files, the exact account mappings, and the exact test assertions — but does **not** spell out source code. The implementing engineer writes the code, test-first, following the descriptions here.

**Goal:** Make every money-moving operation in the SACCO (savings deposit, withdrawal, charge, registration initial deposit) post exactly one balanced journal entry through a single mandatory ledger service, in the same database transaction as the operational record — and backfill the journal entries for historical transactions that were never posted (e.g. Maya Nyamu's 4,000,000 initial deposit in `sacco_bongosacco`).

**Architecture:**
- The accounting tables already exist (`journal_entries`, `journal_entry_lines`, `general_ledger`, `sub_ledger`, `journal_entry_sequences`). **No new accounting tables are created** — deposits/withdrawals/charges are *events*, not *ledgers*; they all post into the one shared journal.
- One canonical posting service (`SavingsJournalService`, exposed behind a `LedgerPostingServiceInterface`) is injected into **every** money path, including the legacy `app/Tenant/Services/` registration flow.
- Every financial `transactions` row gains a `journal_entry_id` foreign key — the integrity link. A financial transaction without a journal entry becomes a detectable defect.
- The current fail-silent `safe()` wrapper is removed: an accounting failure must roll the whole operation back, never "succeed" with unbalanced books.
- A one-off, idempotent, dry-run-capable backfill command generates the missing journal entries for pre-existing transactions, in chronological order, then rebuilds general-ledger running balances.
- A reconciliation guard (test + `accounting:verify` command) asserts every financial transaction is backed by a balanced entry and the trial balance balances.

**Tech Stack:** Laravel 12, MySQL (per-tenant DBs), Pest tests. Money paths span `app/Tenant/Modules/` (modern, layered) and `app/Tenant/Services/` (legacy procedural).

**Scope:** This plan covers **savings deposits, withdrawals, charges, and the member-registration initial deposit + charge**, plus historical backfill and verification. Loan disbursement/repayment and share-purchase paths are a **Phase 2** audit (noted at the end) — they already have partial posting (`LoanAccountingService`, `LoanDisbursementGlTest`) and should be verified separately so this plan stays one testable subsystem.

---

## Reference: the account mapping (the accountant's contract)

Every task below uses this fixed double-entry mapping. GL codes come from `app/Tenant/Modules/Accounting/GlCodes.php` and are resolved via `SavingsCoaResolver`.

| Event | Debit (DR) | Credit (CR) |
|---|---|---|
| **Deposit** | Cash/Bank — payment-mode account (`11101` petty cash, `11102` bank, mobile-money codes) | Member Savings Liability (`21101` mandatory / `21102` voluntary / `21103` fixed) |
| **Withdrawal** | Member Savings Liability (`21101`/`21102`/`21103`) | Cash/Bank — payment-mode account |
| **Charge / fee** | Member Savings Liability (funds taken from the member's balance) | Fee Income (`general_charge.credit_account_id` if set, else `GlCodes::FEE_ACCOUNT_MAINTENANCE`) |

Rules: every journal entry has ≥2 lines; total debits = total credits to the cent; the entry is dated at the transaction's `transaction_date`, not "now"; `journal_type` is `SAVINGS_DEPOSIT` / `SAVINGS_WITHDRAWAL` / `SAVINGS_CHARGE`.

---

## Task 1: Add the integrity link — `transactions.journal_entry_id`

**Files:**
- Create: `database/migrations/tenant/2026_05_20_000001_add_journal_entry_id_to_transactions_table.php`
- Modify: `app/Tenant/Modules/Transactions/Models/Transaction.php`
- Test: `tests/Tenant/Accounting/TransactionJournalLinkTest.php`

- [ ] **Step 1: Write the failing test.** Assert that (a) the `transactions` table has a nullable `journal_entry_id` column, and (b) a `Transaction` model exposes a `journalEntry()` belongsTo relation returning a `JournalEntry`.
- [ ] **Step 2: Run it — expect FAIL** ("column not found" / "method not defined").
- [ ] **Step 3: Implement.** Migration adds a nullable `unsignedBigInteger journal_entry_id` after `gl_credit_account_id`, with a foreign key to `journal_entries(id)` `nullOnDelete`, plus an index. Nullable because historical rows pre-date posting and will be backfilled in Task 8. Add the `journalEntry()` belongsTo relation to the model.
- [ ] **Step 4: Run migration on the test tenant, then run the test — expect PASS.**
- [ ] **Step 5: Commit** — `feat(accounting): link transactions to journal entries via journal_entry_id`.

**Why first:** every later task and the verification depend on this column existing.

---

## Task 2: Define the single posting entry point — `LedgerPostingServiceInterface`

**Files:**
- Create: `app/Tenant/Modules/Accounting/Contracts/LedgerPostingServiceInterface.php`
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` (declare `implements LedgerPostingServiceInterface`)
- Modify: `app/Providers/AppServiceProvider.php` (bind interface → `SavingsJournalService`)
- Test: `tests/Tenant/Accounting/LedgerPostingBindingTest.php`

- [ ] **Step 1: Write the failing test.** Assert `app(LedgerPostingServiceInterface::class)` resolves to a `SavingsJournalService` instance, and that the interface declares `postSavingsDeposit`, `postSavingsWithdrawal`, and `postSavingsCharge` methods.
- [ ] **Step 2: Run it — expect FAIL** ("interface not found").
- [ ] **Step 3: Implement.** Create the interface with the three method signatures (each takes a `Transaction` + `SavingsAccount`, returns a non-nullable `JournalEntry`). Have `SavingsJournalService` implement it (its `postDeposit`/`postWithdrawal`/`postCharge` already match — alias or rename as needed). Bind the interface to the concrete class in `AppServiceProvider::register()`.
- [ ] **Step 4: Run the test — expect PASS.**
- [ ] **Step 5: Commit** — `refactor(accounting): expose SavingsJournalService behind LedgerPostingServiceInterface`.

**Why:** gives the legacy `app/Tenant/Services/` code a clean, injectable seam to call — without those files depending on a concrete class.

---

## Task 3: Make posting mandatory — remove the fail-silent `safe()`

**Files:**
- Modify: `app/Tenant/Modules/Accounting/Services/SavingsJournalService.php` (the `safe()` helper, lines ~466–475, and its call sites)
- Test: `tests/Tenant/Accounting/LedgerPostingFailsLoudlyTest.php`

- [ ] **Step 1: Write the failing test.** With the Chart of Accounts deliberately missing a required GL code, call a deposit through the service inside a DB transaction and assert that (a) it **throws**, and (b) when the caller wraps deposit + posting in one transaction, the `transactions` row is **rolled back** (no orphan operational record without books).
- [ ] **Step 2: Run it — expect FAIL** (today `safe()` swallows the throw and the transaction commits).
- [ ] **Step 3: Implement.** Remove `safe()` (or convert it to log-and-rethrow). Posting methods return a non-nullable `JournalEntry` and propagate any exception. Update the three deposit/withdraw/charge call sites accordingly.
- [ ] **Step 4: Run the test — expect PASS.** Run the full `tests/Tenant/Accounting/` suite to confirm nothing else regressed.
- [ ] **Step 5: Commit** — `fix(accounting): posting failures now roll back the operation instead of failing silently`.

**Why:** a deposit that records money but not the books is worse than a rejected deposit. The books must be all-or-nothing with the cash record.

---

## Task 4: Wire the savings-drawer deposit to the link + atomic posting

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` (`deposit()`, lines ~349–463)
- Test: `tests/Tenant/Accounting/SavingsDepositGlTest.php`

- [ ] **Step 1: Write the failing test.** Seed a tenant with COA. POST a deposit via the route `savings-accounts/{id}/deposit`. Assert: one `journal_entries` row of type `SAVINGS_DEPOSIT`; two balanced `journal_entry_lines` (DR cash, CR savings liability); two `general_ledger` rows; and the `transactions` deposit row has `journal_entry_id` set to that entry.
- [ ] **Step 2: Run it — expect FAIL** (`journal_entry_id` is null today).
- [ ] **Step 3: Implement.** Inside the existing `DB::connection('tenant')->transaction()`, capture the `JournalEntry` returned by the posting call and write its id onto the deposit `Transaction` (and onto each charge `Transaction`). The whole block is already transactional — posting is now part of it.
- [ ] **Step 4: Run the test — expect PASS.**
- [ ] **Step 5: Commit** — `feat(accounting): savings deposit links its transaction to the posted journal entry`.

---

## Task 5: Wire savings withdrawal and savings charges the same way

**Files:**
- Modify: `app/Tenant/Http/Controllers/Api/V1/SavingsAccountController.php` (`withdraw()`, from line ~468)
- Modify: `app/Tenant/Modules/Charges/Services/ChargeJournalService.php` and `app/Tenant/Modules/Charges/Services/ChargeApplicationService.php` (set `journal_entry_id` when a charge posts)
- Test: `tests/Tenant/Accounting/SavingsWithdrawalGlTest.php`, `tests/Tenant/Accounting/SavingsChargeGlTest.php`

- [ ] **Step 1: Write the failing tests.** Withdrawal: assert a `SAVINGS_WITHDRAWAL` entry (DR savings liability, CR cash) and the withdrawal `transactions` row carries `journal_entry_id`. Charge: assert a `SAVINGS_CHARGE` entry (DR savings liability, CR fee income) and the charge `transactions` row + any `member_charges` row carry `journal_entry_id`.
- [ ] **Step 2: Run them — expect FAIL.**
- [ ] **Step 3: Implement.** Mirror Task 4 for `withdraw()`. In the charge services, capture the posted entry id and write it back to the charge transaction / `member_charges` record, all within the existing transaction.
- [ ] **Step 4: Run the tests — expect PASS.**
- [ ] **Step 5: Commit** — `feat(accounting): withdrawal and charge transactions link to their journal entries`.

---

## Task 6: Wire the member-registration initial deposit + charge (the legacy gap)

**Files:**
- Modify: `app/Tenant/Services/MemebersSettingSevices/MemberHelpers.php` (`createNewSaccoMemebers()`, the `$listCharges` loop, lines ~181–207)
- Test: `tests/Tenant/Accounting/RegistrationInitialDepositGlTest.php`

- [ ] **Step 1: Write the failing test.** Register a member with a product that has an `on_registration` deposit charge and an initial deposit (reproduce Maya's case: 4,000,000 deposit, 6,000 charge). Assert: a `SAVINGS_DEPOSIT` entry for the net amount, a `SAVINGS_CHARGE` entry for the fee, balanced lines, `general_ledger` rows, and both `transactions` rows (`type='deposit'` and `type='deposit-charge'`) carry `journal_entry_id`.
- [ ] **Step 2: Run it — expect FAIL** (today this path posts nothing — confirmed: zero `20260520` rows in `journal_entry_sequences` for `sacco_bongosacco`).
- [ ] **Step 3: Implement.** After the `transactions` rows are created inside `createNewSaccoMemebers()`'s existing `transaction()` wrapper, resolve `LedgerPostingServiceInterface` from the container and post: the net deposit and the deposit charge. Write the returned `journal_entry_id` onto each `transactions` row. Note two pre-existing defects to fix here: (a) the transaction-insert loop is currently nested inside the `if ($checkIfShareAccountShouldBeCreated...)` block — move it out so transactions are written regardless of the share setting; (b) `payment_mode` is stored as a numeric id (`107`) — map it to a payment-mode string/GL account before posting so `SavingsCoaResolver::resolvePaymentModeAccount` resolves correctly.
- [ ] **Step 4: Run the test — expect PASS.**
- [ ] **Step 5: Commit** — `fix(accounting): registration initial deposit and charge now post to the ledger`.

---

## Task 7: Backfill command for historical unposted transactions

**Files:**
- Create: `app/Console/Commands/BackfillLedgerEntriesCommand.php` (signature e.g. `accounting:backfill-ledger {--tenant=} {--dry-run}`)
- Create: `app/Tenant/Modules/Accounting/Services/LedgerBackfillService.php`
- Test: `tests/Tenant/Accounting/LedgerBackfillServiceTest.php`

- [ ] **Step 1: Write the failing test.** Seed a tenant with several financial `transactions` rows (deposit, deposit-charge, withdrawal) that have `journal_entry_id IS NULL` — including a duplicate of Maya's two rows. Run the backfill service. Assert: each financial transaction now has a `journal_entry_id`; each generated entry is balanced; entries are dated at the original `transaction_date`; running the backfill a second time creates **no** new entries (idempotent); rows flagged `is_migrated` or already `is_reversed` are skipped per policy.
- [ ] **Step 2: Run it — expect FAIL.**
- [ ] **Step 3: Implement `LedgerBackfillService`.** For one tenant: select all financial `transactions` (`type` in deposit / deposit-charge / charge / general-charge / withdrawal / withdraw-charge) where `journal_entry_id IS NULL`, **ordered by `transaction_date`, then `id`** (chronological — required so general-ledger running balances build up correctly). For each, resolve DR/CR accounts using the Reference mapping, create the journal entry via the canonical posting service dated at `transaction_date`, and set `journal_entry_id`. Skip rows already linked (idempotency) and rows with no resolvable account (collect into a report rather than aborting). After processing, **rebuild `general_ledger` running balances per account in date order** so balances are consistent.
- [ ] **Step 4: Implement the command.** It loops tenants (all, or `--tenant=`), switches DB via the existing `DatabaseSwitcher`, calls the service, and prints a per-tenant summary: posted / skipped / failed counts. `--dry-run` wraps each tenant in a transaction that is rolled back, printing what *would* happen.
- [ ] **Step 5: Run the test — expect PASS.**
- [ ] **Step 6: Commit** — `feat(accounting): add accounting:backfill-ledger command for historical transactions`.

**Operational run (after merge, no code):**
1. `php artisan accounting:backfill-ledger --tenant=sacco_bongosacco --dry-run` — review the summary; confirm Maya's two rows (`id=19`, `id=20`) appear as "to be posted".
2. `php artisan accounting:backfill-ledger --tenant=sacco_bongosacco` — run for real on one tenant.
3. Verify with Task 8's command, then roll out to remaining tenants.

---

## Task 8: Reconciliation guard — `accounting:verify` + integrity test

**Files:**
- Create: `app/Console/Commands/VerifyAccountingIntegrityCommand.php` (signature `accounting:verify {--tenant=}`)
- Create: `app/Tenant/Modules/Accounting/Services/AccountingIntegrityService.php`
- Test: `tests/Tenant/Accounting/AccountingIntegrityTest.php`

- [ ] **Step 1: Write the failing test.** After a deposit + charge, assert the integrity service reports: (a) **zero** financial `transactions` rows with a null `journal_entry_id`; (b) every `journal_entries` row has balanced lines (Σdebit = Σcredit); (c) the trial balance (via existing `TrialBalanceService`) nets to zero. Then inject one unposted transaction and assert the service flags it.
- [ ] **Step 2: Run it — expect FAIL.**
- [ ] **Step 3: Implement `AccountingIntegrityService`** with those three checks, returning a structured report. Implement the command to run it per tenant and exit non-zero if any check fails (so it is CI/cron-friendly).
- [ ] **Step 4: Run the test — expect PASS.**
- [ ] **Step 5: Commit** — `feat(accounting): add accounting:verify integrity command and reconciliation test`.

**Why:** this is the permanent safety net. Once every path posts and the backfill is done, this command/test makes a regression (a new unposted money path) fail loudly instead of silently corrupting the books.

---

## Self-review checklist (done while writing this plan)

- **Spec coverage:** single posting service (Task 2), mandatory/atomic posting (Tasks 3–6), integrity link (Task 1), backfill of missing entries incl. Maya's (Task 7), verification (Task 8). ✔
- **No new accounting tables** — confirmed; all events post into the existing shared journal/ledger, matching the accountant + engineer + manager recommendation. ✔
- **Type consistency:** `LedgerPostingServiceInterface` defined in Task 2 is the type injected in Tasks 5–7; `journal_entry_id` column from Task 1 is the field written in Tasks 4–7 and checked in Task 8. ✔

## Phase 2 (separate plan — not in scope here)

Audit loan disbursement, loan repayment, and share-purchase paths (`LoanDisbursementService`, `LoanRepaymentService`, `TenantLoanService`, share creation in `MemberHelpers`) against the same standard: single posting service, `journal_entry_id` link, mandatory atomic posting, and inclusion in `accounting:verify`. These already have partial coverage (`LoanAccountingService`, `LoanDisbursementGlTest`) so they need verification, not greenfield work.
