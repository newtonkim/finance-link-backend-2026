# Income statement: research, design, and implementation plan

Research date: 27 September 2026. Status: the ledger-based management report is implemented. See [implementation and rollout notes](income-statement-implementation.md) for delivered behavior, validation, and remaining accounting-policy work.

Branches: `feat/income-statement` in both repositories, created from fetched `origin/main` (backend `7337448`, frontend `fffcd25f`).

## Recommendation

Build a period-based, general-ledger-backed SACCO statement of profit or loss, labelled **Income Statement — Surplus / (Deficit)**. Use nature-of-expense detail, current and comparative periods, account drill-down, and consistent exports. The existing `SACCO_UGANDA` chart of accounts supports this direction. This is a proposed management reporting design, not a finding that every tenant applies full IFRS or that the existing postings satisfy it.

Correct reporting requires both correct aggregation and correct upstream recognition. A report cannot repair missing accruals, duplicated income, or interest recognised in the wrong period.

## Accounting research

1. **Presentation and basis.** IAS 1 provides for accrual accounting, comparative information, separate presentation of material items, and expense analysis by nature or function. Choose nature for this application: staff costs, administration, depreciation, and impairment already have distinct accounts. Show the reporting entity, period, currency, and rounding. A profit-or-loss report is one component of financial statements, not a complete set. Sources: [IAS 1 overview](https://www.ifrs.org/issued-standards/list-of-standards/ias-1-presentation-of-financial-statements/) and [IAS 1 text, paragraphs 27–28, 32, 38, 51, 82 and 99–105](https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2022/issued/part-a/ias-1-presentation-of-financial-statements.pdf?bypass=on).

2. **Financial instrument recognition.** IFRS 9 governs classification, measurement, and impairment of financial instruments. Interest on relevant amortised-cost assets uses the effective interest method; impairment charges and reversals belong in profit or loss. The report should consume recognised ledger amounts; recognition calculations belong in accounting services. Sources: [IFRS 9 overview](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-9-financial-instruments/) and [March 2019 IFRIC decision, Curing of a Credit-impaired Financial Asset](https://www.ifrs.org/news-and-events/updates/ifric/2019/ifric-update-march-2019/). The latter explains the distinction between interest revenue and impairment reversals.

3. **2027 transition.** IFRS 18 replaces IAS 1 for annual periods beginning on or after 1 January 2027, with early application permitted. It changes presentation, including categories and subtotals. Classification depends on whether investing or providing finance to customers is a main business activity. Do not apply a generic trading-company template to a SACCO or automatically classify every borrowing cost outside operating activity. Sources: [IFRS 18](https://www.ifrs.org/issued-standards/list-of-standards/ifrs-18-presentation-and-disclosure-in-financial-statements/) and [IFRS 18 key terms](https://www.ifrs.org/supporting-implementation/supporting-materials-by-ifrs-standards/ifrs-18/key-terms/). The [June 2026 IFRIC update](https://www.ifrs.org/news-and-events/updates/ifric/2026/ifric-update-june-2026/) includes a tentative financing-business decision; treat that discussion as tentative, not a final new rule.

4. **Uganda context.** UMRA publishes [SACCO and microfinance guidance](https://umra.go.ug/sacco-and-microfinance-guidebok/) and [standards and guidelines](https://umra.go.ug/standards-guidelines/). These are local starting points, not evidence that this proposed layout is an approved regulatory return. Confirm the tenant's reporting framework and applicable regulator before adding a statutory-compliance label. A search located a SACCO audited report hosted by UMRA, but retrieval failed, so it was not used as layout evidence. Tax exemptions, rates, and regulatory provision percentages are not assumed here.

## Repository findings

| Existing code | Implication |
| --- | --- |
| `GeneralLedger`, `general_ledger` | Has account, journal, accounting date, debit and credit; use period movements, never the stored running balance. |
| `TrialBalanceService` | Useful period aggregation precedent, but uses floats and raw queries; do not copy its arithmetic and authorization assumptions. |
| `BalanceSheetService::surplus()` | Already computes income less expenses; use a same-scope reconciliation, accounting for closing transfers when introduced. |
| `ChartOfAccount`, `SaccoCoaSeeder` | Supports hierarchy and INCOME/EXPENSE types. `ifrs_category` is available but seeded definitions inspected do not populate it. |
| `JournalEntry`, `BranchReadScope` | Journal visibility depends on authorized branches; raw GL queries do not automatically inherit that scope. |
| `SavingsJournalService` | Reversals create opposite entries and change original status to `reversed`; filtering only `posted` can corrupt historical results. |
| `JournalEntryService` | Drafts and posted entries exist; manual journal currency is captured. Establish whether ledger amounts are functional-currency amounts before combining currencies. |
| `LoanDisbursementService::postInterestAccrualEntry()` | Code posts full flat-rate interest upfront. Review recognition before claiming period earnings follow an effective-interest accrual policy. |
| `LoanRepaymentService` | Flat-rate repayments refer to pre-accrued interest, while other paths differ. Test each product's income/receivable treatment end to end. |
| Frontend `src/tenant/layouts/routes.ts` | Income statement currently points to `ComingSoon.vue`. Existing balance-sheet and trial-balance screens provide report conventions. |

No production data was examined. These are code findings, not an accounting audit of tenant balances.

## Proposed report design

Use the existing reports visual language. The statement table is the primary content.

```text
SACCO name
Income Statement — Surplus / (Deficit)
For the period 1 January–30 September 2026 · UGX
Branch scope · Comparison: 1 January–30 September 2025

[Period preset] [From] [To] [Comparison] [Apply] [Export]

Description                               Current     Comparative
Interest income                             ...           ...
Interest expense                            (...)         (...)
Net interest income                         ...           ...
Fee and commission income                   ...           ...
Other operating income                      ...           ...
Operating income before impairment          ...           ...
Credit impairment charge / (reversal)        (...)         (...)
Staff costs                                 (...)         (...)
Administrative expenses                     (...)         (...)
Depreciation and amortisation                (...)         (...)
Other operating expenses                    (...)         (...)
Operating surplus / (deficit)                ...           ...
Investment result*                          ...           ...
Surplus / (deficit) before tax               ...           ...
Income tax expense / (credit)                (...)         (...)
Surplus / (deficit) for the period           ...           ...

Generated timestamp · Expand accounts · Show zero rows
```

*Investment placement is provisional for management reporting. An IFRS 18 presentation needs an entity-specific category policy; some investment or financing items belong in operating activity. Keep report-line mapping distinct from accounting-standard category mapping. Add discontinued operations separately if applicable. OCI and transfers to reserves are outside this profit-or-loss result.

Right-align tabular numerals; use parentheses for deductions and losses. Expose exact period dates, currency, and authorized branch scope in screen and exports. Account rows expand and open a paginated ledger drawer. Computed subtotal rows are not accounts. Hide a zero row only when it is zero in both periods and has no visible children. Use accessible table headers, keyboard-operable expand controls, and text rather than colour alone to indicate losses. Mobile tables can scroll horizontally while retaining readable labels.

Presets: month, quarter, financial year to date, and custom dates. Default comparison is the corresponding prior-year period with explicit leap-day handling. Read configured financial years rather than assuming January. If no financial year exists, require dates or show an explicit calendar-year fallback. Stale responses must not overwrite newer filters; exports must use the displayed response snapshot. Empty results and failed requests have distinct states.

## Account mapping and calculation

Suggested bootstrap mappings from the shipped template, subject to tenant customization:

| Accounts | Report line |
| --- | --- |
| 41000 descendants | Interest income; separately disclose penalty/default interest when material |
| 51100, 51200 | Interest expense; assess funding policy before statutory categorization |
| 42000 descendants | Fee income; verify whether any origination fees should form part of effective interest |
| 43000 | Other operating income |
| 44000 | Investment result; classification policy required |
| 51300 descendants | Impairment; distinguish write-offs against existing allowance from additional expense |
| 52000 descendants | Staff costs |
| 53000 descendants | Administrative expenses |
| 54000 descendants | Depreciation and amortisation |
| 55000 | Other operating expenses |
| 56000 | Income tax; verify content is income tax rather than other levies |

Codes are bootstrap hints, not runtime accounting rules. Persist a validated report-line key per account, support inheritance from a mapped ancestor, and allow explicit overrides. Preserve tenant customizations and include a mapping version in the response. Map each account's own movement exactly once; parent roll-ups must not be counted again. Detect cycles, missing parents, and direct postings to headers.

Include unmapped income/expense movements in clearly labelled unclassified lines and in the final result. Expose the affected accounts and mark classification incomplete; never silently omit money or imply complete intermediate subtotals. Include historically used inactive and soft-deleted accounts.

Calculation rules (engineering recommendations):

- Income account amount = sum(credit) − sum(debit); expense amount = sum(debit) − sum(credit). Preserve contra balances, refunds, and negative totals. Do not use absolute values.
- Final surplus = sum(credit − debit) over all included INCOME and EXPENSE movements. Asset purchases, loan principal, savings principal, share capital, and equity distributions do not enter this sum.
- Use inclusive ledger accounting dates. Do not use payment dates, creation timestamps, lifetime totals, opening balances, or schedule amounts.
- Include GL movements from both posted and reversed originals, plus posted reversal journals, each on its own date. Exclude drafts. A February reversal must not erase January's original income.
- Introduce explicit closing-entry metadata before supporting year-end closing. Exclude profit-transfer closing entries from performance, while retaining genuine adjusting entries. Do not infer closing journals from narration. Reconcile the exclusion to the trial balance.
- Aggregate DECIMAL values in SQL and use decimal arithmetic for subtotals. Return money as decimal strings, with consistent two-decimal storage precision; round only for display. Verify BCMath availability in runtime and CI.
- Respect tenant connection and authorized journal branch IDs for totals, comparisons, and drill-down. A raw join bypasses Eloquent global scopes unless restrictions are added explicitly. Never trust a requested branch ID alone.
- Only combine amounts established to be in the tenant's functional currency. Until currency conversion behavior is verified, reject unsupported mixed-currency data with an actionable diagnostic.
- Report generation is read-only: it must not create interest accrual, depreciation, impairment, tax, or closing journals.

## Backend implementation sequence

1. Audit recognition paths and functional-currency behavior. Establish a shared query scope for posted ledger movements, reversals, branch authorization, and closing exclusions. Keep reporting changes separate from any historical accounting corrections.
2. Add an additive tenant migration for account-to-report-line mapping. Bootstrap known template accounts without overwriting custom mappings. Validate type compatibility and support unmapped accounts safely.
3. Add `IncomeStatementServiceInterface`, `IncomeStatementService`, request validation, controller, resources, and container binding beside the existing accounting reports.
4. Add authenticated `GET /reports/income-statement` and `GET /reports/income-statement/ledger` under the existing tenant API route group, with reporting authorization checked server-side.
5. Accept `from`, `to`, optional paired `compare_from`/`compare_to`, and `hide_zero`. Validate strict dates, ordering, paired comparison fields, and supported scope. Always return the resolved dates.
6. Response contains entity, currency, basis/policy description, dates, scope, generated timestamp, mapping version, ordered sections, nested account lines, exact subtotals, final surplus, and classification/reconciliation diagnostics. Each account includes ID, code, name, current and comparison amounts; computed rows use stable string keys.
7. Drill-down uses precisely the same predicate as the statement. Reconstruct running movement by accounting date and stable ledger ID, including earlier pages; the existing trial-balance drawer API must be reviewed before reuse because its running balance calculation starts afresh per page.
8. Reconcile to income/expense trial-balance period movement under identical scope and exclusions. For financial-year-to-date periods reconcile to balance-sheet current-year surplus, with an explicit bridge for closing transfers or other scope differences. Do not demand that an arbitrary month's result equal cumulative equity.

## Frontend implementation sequence

Add `incomeStatementApi.ts`, typed response models, `useIncomeStatement.ts`, `IncomeStatement.vue`, and a report-row component if existing rows cannot handle semantic subtotals. Replace the placeholder route and update the reports index. Reuse accounting formatting and drawer presentation where suitable, but have the backend own monetary calculations. Build PDF/Excel/CSV exports from the same response, including period, comparison, currency, scope, mapping diagnostics, and generated timestamp. Sanitize text cells against spreadsheet formula execution.

## Acceptance tests

Use the existing tenant accounting test harness and frontend report test conventions.

| Scenario | Required result |
| --- | --- |
| Interest 1,000; funding 100; fees 200; impairment 50; staff/admin 300; depreciation 20; investment 40; tax 70 | Net interest 900; pre-impairment operating income 1,100; operating surplus 730; pre-tax 770; final surplus 700 under the proposed management mapping |
| Principal disbursement/repayment, deposits, share purchases | No profit-or-loss effect |
| Accrued interest followed by receipt | Income counted once; receipt settles the receivable |
| January income 100 reversed in February | January +100; February −100; combined zero |
| Draft, out-of-period, unauthorized-branch, other-tenant records | Excluded from report and drill-down |
| Income debit / expense credit | Reduces income / expense without sign loss |
| Custom, unmapped, inactive, deleted, or header-posted accounts | No omitted movements or duplicate counting; clear diagnostics |
| Fractional values and large aggregates | Exact decimal equality between rows, totals, API and exports |
| Comparative-only movement and leap-year periods | Correct dates and retained comparative rows |
| Closing transfer plus adjusting journal | Transfer excluded; adjustment included; reconciliation bridge explains difference |
| Multiple drill-down pages and backdated postings | Stable ordering and correct cumulative movement across pages |
| Empty period, unsupported currency, network error, rapid filter changes | Distinct states; no fabricated data or stale exports |

Before describing the result as an accrual-compliant financial statement, resolve the upfront flat-interest recognition finding and verify accrual, fee, impairment, depreciation, and tax posting completeness. A ledger-faithful management report can be delivered earlier with an accurate basis description.
