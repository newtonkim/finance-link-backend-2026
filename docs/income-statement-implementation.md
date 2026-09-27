# Income statement implementation

Implemented on `feat/income-statement` in both repositories. Frontend route: `/tenant/reports/income-statement`.

## Delivered

- SACCO income statement with current/comparative periods, financial-year defaults, month/quarter presets, expandable account detail, and PDF/Excel/CSV exports.
- Backend endpoints: `GET /api/v1/tenant/reports/income-statement` and `GET /api/v1/tenant/reports/income-statement/ledger`.
- Exact decimal aggregation of posted and reversed-original ledger movements by accounting date. Returned monetary strings are signed contributions to surplus (`credit - debit`), so expenses normally display in parentheses.
- Tenant isolation, authorized branch selection, and ledger detail pinned to the displayed report's selected branch. The shared frontend client preserves explicitly supplied branch filters instead of replacing them with the currently active branch.
- Exclusion of journals explicitly marked `is_closing_entry`, with closing-transfer amounts disclosed as a reconciliation bridge. This adds closing metadata; it does not implement a year-end closing workflow.
- Persistent account mapping, inherited mappings, explicit overrides, and type validation. Known template account identities are mapped directly, including leaves, because existing seeded accounts can lack parent links. Tenant-customized identities are not guessed from account codes.
- Chart of Accounts provides an income-statement mapping selector, including on account creation. No mapping means inherit from the parent or report as unclassified.
- Unmapped, inactive, soft-deleted, and directly posted header accounts are not silently discarded. Cycles and incompatible parent types produce diagnostics. Account detail contains each account's own movement; section expansion replaces recursive header roll-ups to avoid double-counting and work with the existing flat template.
- Running ledger movement is reconstructed by accounting date and ledger ID across pages, not read from stored running balances.
- Exports include all returned account detail, scope, currency, accounting basis, classification diagnostics, mapping version, reconciliation, and generation time. Excel amounts remain decimal text to preserve values beyond Excel's numeric precision; labels are protected against spreadsheet formula interpretation.

## Accounting limits

This is a **management report of existing recognized ledger activity**, currently limited to UGX. It refuses unsupported foreign-currency movements rather than combining monetary units. It does not recompute interest recognition, depreciation, impairment, tax, or historical journals.

The existing flat-rate loan workflow recognizes interest upfront. That policy and the other loan recognition paths still need separate accounting review before claiming accrual or IFRS compliance. IFRS 18 statutory category policies, regulatory returns, discontinued operations, and OCI are not implemented by this management-report layout. Intermediate subtotals are provisional when accounts are unclassified; the final surplus still includes their movement.

The reconciliation check compares statement totals to the scoped income/expense GL movement. Tests also compare the result with the existing trial balance and balance-sheet current-year surplus for matching periods and scope. The existing reports' broader scoping/precision behavior has not been rewritten.

## Rollout

Deploy the additive tenant migration before making the new report available:

`database/migrations/tenant/2026_09_27_000001_add_income_statement_metadata.php`

It adds `chart_of_accounts.income_statement_line` and `journal_entries.is_closing_entry` and initializes mappings for recognized template accounts. Existing journal amounts are not changed.

The repository's existing `php artisan tenant:migrate-all` command applies pending tenant migrations to **all active tenants**. Use it as part of the intended environment's normal rollout. This development session applied migrations only to the test database, not live tenant databases. Both backend and frontend changes are needed for this feature.

After deployment, open the statement, review any account-classification diagnostics, and adjust mappings in Chart of Accounts. The report uses the active branch supplied by the application, subject to server-side access checks; requests without a branch cover the staff member's authorized scope.

## Validation

- 66 backend tests passed (232 assertions): accounting suite, chart-of-accounts HTTP tests, and account seeding. Includes 15 new report tests for arithmetic, reversals, branch restrictions, closing entries, inheritance/cycles, historical accounts, comparisons, currency rejection, large decimals, API validation and reconciliation.
- 43 frontend tests passed: report rendering and drill-down, date validation, stale responses, export precision/formula protection, existing report regressions, account creation, and branch snapshot preservation.
- Vue TypeScript check, targeted ESLint, production build, PHP syntax checks, and changed PHP formatting passed.
- Chrome checks with a fixture response: desktop (1440px), mobile (390px), no page-level mobile overflow, no page errors, and comparative ledger drawer opening. This was an isolated rendering check, not a live tenant-data reconciliation.
- Existing chart-of-accounts test actors were updated to `status = active` to satisfy the current staff API middleware.
