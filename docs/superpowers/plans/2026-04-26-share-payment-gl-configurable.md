# Configurable Share Payment GL Account Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded `gl_code = '1111'` DR account in `ShareAccountingService` with an admin-configurable `share_payment_account_id` FK stored in `OnboardingSettings`, surfaced in the Share Management Settings drawer.

**Architecture:** Add a nullable FK `share_payment_account_id → chart_of_accounts(id)` to `onboarding_settings`. `ShareAccountingService` reads `OnboardingSettings::current()->share_payment_account_id` at journal-entry time; if null it logs a warning and skips. The frontend's existing Share Management Settings drawer gains a `SearchableSelect` for picking the account, using the same `chartOfAccountsApi.list()` + account mapping pattern as `LoanProductCreate.vue`.

**Tech Stack:** Laravel 12 (PHP 8.2), Eloquent, PostgreSQL, Vue 3, TypeScript, Pinia, Vite

---

## File Map

**Backend — create:**
- `database/migrations/tenant/2026_04_30_000001_add_share_payment_account_id_to_onboarding_settings_table.php`

**Backend — modify:**
- `app/Tenant/Modules/Settings/Models/OnboardingSettings.php`
- `app/Http/Requests/Tenant/OnboardingSettingsRequest.php`
- `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`
- `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php`

**Frontend — modify:**
- `src/tenant/apis/onboardingSettings/api.ts`
- `src/stores/settingsStore.ts`
- `src/tenant/modules/settings/composables/useSharesSettings.ts`
- `src/tenant/modules/settings/components/shares-dividends/ManageSharesDrawer.vue`

---

## Task 1: Migration, Model, and FormRequest

**Files:**
- Create: `database/migrations/tenant/2026_04_30_000001_add_share_payment_account_id_to_onboarding_settings_table.php`
- Modify: `app/Tenant/Modules/Settings/Models/OnboardingSettings.php`
- Modify: `app/Http/Requests/Tenant/OnboardingSettingsRequest.php`

- [ ] **Step 1: Create the migration file**

Create `database/migrations/tenant/2026_04_30_000001_add_share_payment_account_id_to_onboarding_settings_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('share_payment_account_id')->nullable()->after('share_price');
            $table->foreign('share_payment_account_id')
                ->references('id')->on('chart_of_accounts')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
            $table->dropForeign(['share_payment_account_id']);
            $table->dropColumn('share_payment_account_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
php artisan tenants:migrate
```

Expected output: `Migration table created successfully` (or similar), no errors. If you only have a single tenant in dev, you can also run:
```bash
php artisan migrate --database=tenant
```

- [ ] **Step 3: Update `OnboardingSettings` model**

Open `app/Tenant/Modules/Settings/Models/OnboardingSettings.php`. Make these three changes:

1. Add `use App\Tenant\Modules\Accounting\Models\ChartOfAccount;` after the namespace declaration.
2. Add `use Illuminate\Database\Eloquent\Relations\BelongsTo;` after the previous use statement.
3. Add `'share_payment_account_id'` to `$fillable` (after `'share_price'`).
4. Add `'share_payment_account_id' => 'integer'` to `$casts`.
5. Add the relation method at the end of the class (before the closing `}`):

```php
public function sharePaymentAccount(): BelongsTo
{
    return $this->belongsTo(ChartOfAccount::class, 'share_payment_account_id');
}
```

The full updated file should look like:

```php
<?php

namespace App\Tenant\Modules\Settings\Models;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingSettings extends Model
{
    protected $connection = 'tenant';

    protected $table = 'onboarding_settings';

    protected $fillable = [
        'shares_compulsory',
        'min_shares_on_onboarding',
        'share_price',
        'share_payment_account_id',
        'shares_compulsory_applies_to_existing',
        'auto_create_savings_account',
        'require_member_approval',
        'loyal_member_min_tenure_months',
        'hide_initial_deposit_field',
        'hide_opening_balance_field',
        'hide_is_shareholder_field',
        'reversal_requires_approval',
        'reversal_approver_roles',
        'reversal_max_days',
    ];

    protected $casts = [
        'shares_compulsory' => 'boolean',
        'min_shares_on_onboarding' => 'integer',
        'share_price' => 'decimal:2',
        'share_payment_account_id' => 'integer',
        'shares_compulsory_applies_to_existing' => 'boolean',
        'auto_create_savings_account' => 'boolean',
        'require_member_approval' => 'boolean',
        'loyal_member_min_tenure_months' => 'integer',
        'hide_initial_deposit_field' => 'boolean',
        'hide_opening_balance_field' => 'boolean',
        'hide_is_shareholder_field' => 'boolean',
        'reversal_requires_approval' => 'boolean',
        'reversal_approver_roles' => 'array',
        'reversal_max_days' => 'integer',
    ];

    /** Always return the single row, creating it with defaults if it does not exist. */
    public static function current(): self
    {
        return self::firstOrCreate([], [
            'shares_compulsory' => false,
            'min_shares_on_onboarding' => 1,
            'share_price' => 0.00,
            'share_payment_account_id' => null,
            'shares_compulsory_applies_to_existing' => false,
            'auto_create_savings_account' => true,
            'require_member_approval' => false,
            'loyal_member_min_tenure_months' => 12,
            'hide_initial_deposit_field' => false,
            'hide_opening_balance_field' => false,
            'hide_is_shareholder_field' => false,
            'reversal_requires_approval' => false,
            'reversal_approver_roles' => [],
            'reversal_max_days' => 0,
        ]);
    }

    public function sharePaymentAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'share_payment_account_id');
    }
}
```

- [ ] **Step 4: Update `OnboardingSettingsRequest`**

Open `app/Http/Requests/Tenant/OnboardingSettingsRequest.php`. Add this rule inside `rules()`, after the `'share_price'` rule:

```php
'share_payment_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
```

The full `rules()` method:

```php
public function rules(): array
{
    return [
        'shares_compulsory' => ['sometimes', 'boolean'],
        'min_shares_on_onboarding' => ['sometimes', 'integer', 'min:0'],
        'share_price' => ['sometimes', 'numeric', 'min:0'],
        'share_payment_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
        'shares_compulsory_applies_to_existing' => ['sometimes', 'boolean'],
        'auto_create_savings_account' => ['sometimes', 'boolean'],
        'require_member_approval' => ['sometimes', 'boolean'],
        'loyal_member_min_tenure_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        'hide_initial_deposit_field' => ['sometimes', 'boolean'],
        'hide_opening_balance_field' => ['sometimes', 'boolean'],
        'hide_is_shareholder_field' => ['sometimes', 'boolean'],
        'reversal_requires_approval' => ['sometimes', 'boolean'],
        'reversal_approver_roles' => ['sometimes', 'array'],
        'reversal_approver_roles.*' => ['string'],
        'reversal_max_days' => ['sometimes', 'integer', 'min:0'],
    ];
}
```

- [ ] **Step 5: Run lint**

```bash
composer lint
```

Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/tenant/2026_04_30_000001_add_share_payment_account_id_to_onboarding_settings_table.php \
        app/Tenant/Modules/Settings/Models/OnboardingSettings.php \
        app/Http/Requests/Tenant/OnboardingSettingsRequest.php
git commit -m "feat: add share_payment_account_id to onboarding_settings"
```

---

## Task 2: Update ShareAccountingService and Tests

**Files:**
- Modify: `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`
- Modify: `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php`

### Context

`ShareAccountingService::postSharePurchaseEntry()` currently does:
```php
$cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
```

We replace this with a settings lookup. The CR side (`3110` — Share Capital) remains hardcoded.

The existing test file at `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php` has two tests:
1. `test_share_purchase_posts_balanced_journal_entry` — happy path, checks DR on `1111`
2. `test_share_purchase_skips_entry_when_gl_not_configured` — skip path

After our change the happy path test needs `OnboardingSettings` configured with `share_payment_account_id`. We also add a new test confirming a different configured account is used.

- [ ] **Step 1: Update the existing happy-path test to configure OnboardingSettings**

In `tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php`, update the `setUp()` method to add an import for `OnboardingSettings` and configure it after the GL accounts are created.

Add this import at the top (after existing `use` statements):
```php
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
```

Then at the end of `setUp()`, after both `ChartOfAccount::create()` calls, add:
```php
$cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
OnboardingSettings::current()->update(['share_payment_account_id' => $cashGl->id]);
```

The full updated `setUp()`:

```php
protected function setUp(): void
{
    parent::setUp();

    $this->staff = Staff::create([
        'name' => 'Accounting Tester',
        'email' => 'acct@test.com',
        'password' => Hash::make('password'),
        'role' => 'Admin',
        'is_tenant_admin' => true,
    ]);

    $this->member = Member::create([
        'name' => 'Test Member',
        'member_number' => 'MBR-SHR-001',
        'code' => 'MBR-SHR-001',
        'password' => Hash::make('password'),
        'status' => 'active',
        'phone' => '0700000001',
    ]);

    ChartOfAccount::create([
        'gl_code' => '1111', 'name' => 'Petty Cash', 'account_type' => 'ASSET',
        'account_subtype' => 'Cash', 'normal_balance' => 'DR',
        'level' => 3, 'is_control' => false, 'is_postable' => true,
    ]);
    ChartOfAccount::create([
        'gl_code' => '3110', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY',
        'account_subtype' => 'Share Capital', 'normal_balance' => 'CR',
        'level' => 3, 'is_control' => false, 'is_postable' => true,
    ]);

    $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
    OnboardingSettings::current()->update(['share_payment_account_id' => $cashGl->id]);
}
```

- [ ] **Step 2: Add new test for configurable account**

Add this test method to `SharePurchaseAccountingTest` after the existing happy-path test:

```php
public function test_share_purchase_uses_configured_payment_account(): void
{
    // Create a bank account — different from the default 1111 (Petty Cash)
    $bankGl = ChartOfAccount::create([
        'gl_code' => '1121', 'name' => 'Bank Account', 'account_type' => 'ASSET',
        'account_subtype' => 'Bank', 'normal_balance' => 'DR',
        'level' => 3, 'is_control' => false, 'is_postable' => true,
    ]);

    // Override OnboardingSettings to use the bank account
    OnboardingSettings::current()->update(['share_payment_account_id' => $bankGl->id]);

    $share = Share::create([
        'member_id' => $this->member->id,
        'share_no' => 8,
        'share_value' => 500.00,
        'total_value' => 4000.00,
        'purchased_at' => now()->toDateString(),
    ]);

    $service = app(ShareAccountingServiceInterface::class);
    $service->postSharePurchaseEntry($share, $this->staff->id);

    $je = JournalEntry::on('tenant')
        ->where('reference_type', 'share')
        ->where('reference', "SHR-{$share->id}")
        ->first();

    $this->assertNotNull($je, 'Expected a journal entry for share purchase');

    $lines = $je->lines()->get();
    $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();

    $drLine = $lines->firstWhere('account_id', $bankGl->id);
    $crLine = $lines->firstWhere('account_id', $shareCapitalGl->id);

    $this->assertNotNull($drLine, 'Expected DR line on Bank Account (1121), not Petty Cash');
    $this->assertNotNull($crLine, 'Expected CR line on Share Capital (3110)');
    $this->assertEquals(4000.00, (float) $drLine->debit);
    $this->assertEquals(4000.00, (float) $crLine->credit);
    $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
}
```

- [ ] **Step 3: Run tests to confirm failures**

```bash
php artisan test --filter=SharePurchaseAccountingTest
```

Expected: at minimum the happy-path test and the new test fail with something like `assertNotNull failed` or `Expected DR line` error (the service still reads GL `1111` by code, not from settings). The skip test should still pass.

- [ ] **Step 4: Update `ShareAccountingService`**

Open `app/Tenant/Modules/Shares/Services/ShareAccountingService.php`.

Add this import after the existing `use` statements:
```php
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
```

Replace:
```php
$cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
$shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();

if (! $cashGl || ! $shareCapitalGl) {
    Log::warning('ShareAccountingService: GL accounts 1111 or 3110 not found — share purchase JE skipped.', [
        'share_id' => $share->id,
        'member_id' => $share->member_id,
    ]);

    return;
}
```

With:
```php
$settings = OnboardingSettings::current();
$cashGl = $settings->share_payment_account_id
    ? ChartOfAccount::on('tenant')->find($settings->share_payment_account_id)
    : null;
$shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '3110')->first();

if (! $cashGl || ! $shareCapitalGl) {
    Log::warning('ShareAccountingService: share payment account or GL 3110 not configured — share purchase JE skipped.', [
        'share_id' => $share->id,
        'member_id' => $share->member_id,
        'share_payment_account_id' => $settings->share_payment_account_id,
    ]);

    return;
}
```

- [ ] **Step 5: Run tests to confirm all pass**

```bash
php artisan test --filter=SharePurchaseAccountingTest
```

Expected: all 3 tests pass (happy-path, configurable-account, skip).

- [ ] **Step 6: Run full test suite**

```bash
composer test
```

Expected: no failures.

- [ ] **Step 7: Commit**

```bash
git add app/Tenant/Modules/Shares/Services/ShareAccountingService.php \
        tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php
git commit -m "feat: ShareAccountingService reads share payment account from OnboardingSettings"
```

---

## Task 3: Frontend API Type and Settings Store

**Files:**
- Modify: `src/tenant/apis/onboardingSettings/api.ts`
- Modify: `src/stores/settingsStore.ts`

- [ ] **Step 1: Add field to the TypeScript interface**

Open `src/tenant/apis/onboardingSettings/api.ts`. Add `share_payment_account_id?: number | null` to the `OnboardingSettings` interface, after `share_price`:

```typescript
export interface OnboardingSettings {
    id?: number
    shares_compulsory?: boolean
    min_shares_on_onboarding?: number
    share_price?: number
    share_payment_account_id?: number | null
    shares_compulsory_applies_to_existing?: boolean
    auto_create_savings_account?: boolean
    require_member_approval?: boolean
    loyal_member_min_tenure_months?: number
    hide_initial_deposit_field?: boolean
    hide_opening_balance_field?: boolean
    hide_is_shareholder_field?: boolean
    reversal_requires_approval?: boolean
    reversal_approver_roles?: string[]
    reversal_max_days?: number
}
```

No changes needed to the `onboardingSettingsApi` object itself.

- [ ] **Step 2: Add `sharePaymentAccountId` to settingsStore**

Open `src/stores/settingsStore.ts`. Make three changes:

1. Add `const sharePaymentAccountId = ref<number | null>(null)` after the `loyalMemberMinTenureMonths` ref (line ~29):

```typescript
const sharePaymentAccountId = ref<number | null>(null)
```

2. Add this line inside `applyOnboardingSettings()`, after the `loyalMemberMinTenureMonths` assignment:

```typescript
sharePaymentAccountId.value = data.share_payment_account_id ?? null
```

3. Add `sharePaymentAccountId` to the returned object:

```typescript
return {
    hideInitialDeposit,
    hideOpeningBalance,
    hideIsShareholderField,
    setHideInitialDeposit,
    setHideOpeningBalance,
    setHideIsShareholderField,
    // onboarding
    sharesCompulsory,
    minSharesOnOnboarding,
    sharePrice,
    sharePaymentAccountId,
    sharesCompulsoryAppliesToExisting,
    autoCreateSavingsAccount,
    requireMemberApproval,
    loyalMemberMinTenureMonths,
    onboardingSettingsLoaded,
    fetchOnboardingSettings,
    saveOnboardingSettings,
}
```

- [ ] **Step 3: Run type-check**

```bash
cd /path/to/mfuko-pro-frontend-2026 && pnpm type-check
```

Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add src/tenant/apis/onboardingSettings/api.ts \
        src/stores/settingsStore.ts
git commit -m "feat: add sharePaymentAccountId to onboarding settings store"
```

---

## Task 4: Frontend Composable and Drawer UI

**Files:**
- Modify: `src/tenant/modules/settings/composables/useSharesSettings.ts`
- Modify: `src/tenant/modules/settings/components/shares-dividends/ManageSharesDrawer.vue`

### Context

`useSharesSettings.ts` manages state for the Share Management Settings drawer. `ManageSharesDrawer.vue` is a pure presentational component that receives everything via props and emits updates — it currently has no awareness of GL accounts.

The `chartOfAccountsApi.list({ list: 1 })` call returns `{ data: { data: Account[] } }` where each account has `id`, `gl_code`, `name`, `is_postable`. The `SearchableSelect` component (imported from `@/Global/SearchableSelect.vue`) expects `:options` as `{ id: number; name: string }[]` and binds its value via `v-model` (which is the account `id`).

- [ ] **Step 1: Update `useSharesSettings.ts` composable**

Replace the full file content with:

```typescript
import { ref, reactive, computed } from 'vue'
import { toast } from 'vue-sonner'
import { useSettingsStore } from '@/stores/settingsStore'
import { chartOfAccountsApi } from '@/tenant/apis/chartOfAccounts/chartOfAccountsApi'

export function useSharesSettings() {
    const settingsStore = useSettingsStore()

    // ── Manage Shares Drawer State ──────────────────────────────────────────
    const sharesDrawerOpen = ref(false)
    const sharesDrawerSaving = ref(false)
    const sharesDrawerLoading = ref(false)

    const tempHideIsShareholderField = ref(settingsStore.hideIsShareholderField)
    const tempSharesCompulsory = ref(false)
    const tempMinShares = ref(1)
    const tempSharePrice = ref<number | string>(0)
    const tempAppliesToExisting = ref(false)
    const tempSharePaymentAccountId = ref<number | null>(null)

    const accounts = ref<{ id: number; name: string }[]>([])

    const minInvestment = computed(() =>
        Number(tempMinShares.value) * Number(tempSharePrice.value)
    )

    async function openSharesDrawer() {
        sharesDrawerOpen.value = true
        sharesDrawerLoading.value = true
        try {
            await settingsStore.fetchOnboardingSettings()
            tempHideIsShareholderField.value = settingsStore.hideIsShareholderField
            tempSharesCompulsory.value = settingsStore.sharesCompulsory
            tempMinShares.value = settingsStore.minSharesOnOnboarding
            tempSharePrice.value = settingsStore.sharePrice
            tempAppliesToExisting.value = settingsStore.sharesCompulsoryAppliesToExisting
            tempSharePaymentAccountId.value = settingsStore.sharePaymentAccountId

            const res = await chartOfAccountsApi.list({ list: 1 })
            const all: any[] = Array.isArray(res.data?.data)
                ? res.data.data
                : Array.isArray(res.data)
                    ? res.data
                    : []
            accounts.value = all
                .filter((a: any) => a.is_postable)
                .map((a: any) => ({ id: a.id, name: `${a.gl_code} - ${a.name}` }))
        } finally {
            sharesDrawerLoading.value = false
        }
    }

    async function saveSharesSettings() {
        sharesDrawerSaving.value = true
        try {
            settingsStore.setHideIsShareholderField(tempHideIsShareholderField.value)
            await settingsStore.saveOnboardingSettings({
                shares_compulsory: tempSharesCompulsory.value,
                min_shares_on_onboarding: Number(tempMinShares.value),
                share_price: Number(tempSharePrice.value),
                shares_compulsory_applies_to_existing: tempAppliesToExisting.value,
                hide_is_shareholder_field: Boolean(tempHideIsShareholderField.value),
                auto_create_savings_account: settingsStore.autoCreateSavingsAccount,
                require_member_approval: settingsStore.requireMemberApproval,
                share_payment_account_id: tempSharePaymentAccountId.value,
            })
            toast.success('Share management settings saved.')
            sharesDrawerOpen.value = false
        } catch {
            toast.error('Failed to save settings. Please try again.')
        } finally {
            sharesDrawerSaving.value = false
        }
    }

    // ── Dividend Drawer State ───────────────────────────────────────────────
    const dividendDrawerOpen = ref(false)
    const dividendDrawerSaving = ref(false)

    const dividendForm = reactive({
        distribution_account_type: 'shareholders_only' as 'shareholders_only' | 'all_accounts',
        distribution_basis: 'proportional' as 'proportional' | 'equal',
        frequency: 'annually' as 'monthly' | 'quarterly' | 'semi_annually' | 'annually',
        distribution_day: 1,
        distribution_month: 12,
        dividend_rate: '' as string | number,
        minimum_shares: '' as string | number,
        minimum_dividend_amount: '' as string | number,
        rounding: 'nearest' as 'nearest' | 'floor' | 'ceil',
        auto_distribute: false,
        carry_forward_remainder: true,
    })

    const accountTypeOptions = [
        { value: 'shareholders_only', label: 'Share holders only' },
        { value: 'all_accounts', label: 'All sacco accounts' },
    ]

    const frequencyOptions = [
        { value: 'monthly', label: 'Monthly' },
        { value: 'quarterly', label: 'Quarterly' },
        { value: 'semi_annually', label: 'Semi-annually' },
        { value: 'annually', label: 'Annually' },
    ]

    const basisOptions = [
        { value: 'proportional', label: 'Proportional to shares held' },
        { value: 'equal', label: 'Equal distribution' },
    ]

    const roundingOptions = [
        { value: 'nearest', label: 'Round to nearest' },
        { value: 'floor', label: 'Round down (floor)' },
        { value: 'ceil', label: 'Round up (ceiling)' },
    ]

    const months = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ]

    async function saveDividendSettings() {
        dividendDrawerSaving.value = true
        try {
            await new Promise(resolve => setTimeout(resolve, 700))
            toast.success('Dividend settings saved successfully.')
            dividendDrawerOpen.value = false
        } catch {
            toast.error('Failed to save dividend settings.')
        } finally {
            dividendDrawerSaving.value = false
        }
    }

    return {
        // Shares Logic
        sharesDrawerOpen,
        sharesDrawerSaving,
        sharesDrawerLoading,
        tempHideIsShareholderField,
        tempSharesCompulsory,
        tempMinShares,
        tempSharePrice,
        tempAppliesToExisting,
        tempSharePaymentAccountId,
        accounts,
        minInvestment,
        openSharesDrawer,
        saveSharesSettings,

        // Dividend Logic
        dividendDrawerOpen,
        dividendDrawerSaving,
        dividendForm,
        saveDividendSettings,

        // Options
        accountTypeOptions,
        frequencyOptions,
        basisOptions,
        roundingOptions,
        months,
    }
}
```

- [ ] **Step 2: Update `ManageSharesDrawer.vue` props and emit**

Open `src/tenant/modules/settings/components/shares-dividends/ManageSharesDrawer.vue`.

Update the `<script setup>` block. Add `BookOpen` to the lucide imports, add `SearchableSelect` import, add two new props (`sharePaymentAccountId` and `accounts`), and add `update:sharePaymentAccountId` to `defineEmits`:

```vue
<script setup lang="ts">
import { X, Share2, ShieldCheck, Percent, AlertCircle, Users, Loader2, BookOpen } from 'lucide-vue-next'
import { formatMoneyValue } from '@/Global'
import SearchableSelect from '@/Global/SearchableSelect.vue'

defineProps<{
    show: boolean
    loading: boolean
    saving: boolean
    currencyCode: string
    sharesCompulsory: boolean
    minShares: number
    sharePrice: number | string
    appliesToExisting: boolean
    minInvestment: number
    hideIsShareholderField: boolean
    sharePaymentAccountId: number | null
    accounts: { id: number; name: string }[]
    saveSettings: () => void
}>()

const emit = defineEmits([
    'update:show',
    'update:sharesCompulsory',
    'update:minShares',
    'update:sharePrice',
    'update:appliesToExisting',
    'update:hideIsShareholderField',
    'update:sharePaymentAccountId',
])
</script>
```

- [ ] **Step 3: Add "Accounting" section to the drawer body**

In the `<template>` of `ManageSharesDrawer.vue`, add a new section after the closing `</div>` of Section 2 (Member Registration Fields) and before the closing `</template>` of the `v-else` block.

Insert this block after the closing `</div>` of the second section (around line 214 — the one that ends `<!-- ── Section 2: Member Registration Fields ── -->`):

```vue
<!-- ── Section 3: Accounting ── -->
<div class="overflow-hidden rounded-2xl border border-neutral-100 dark:border-neutral-800">
    <div
        class="flex items-center gap-2.5 border-b border-neutral-100 bg-neutral-50 px-4 py-3 dark:border-neutral-800 dark:bg-neutral-900">
        <BookOpen class="h-4 w-4 text-nfuko-primary dark:text-bg-nfuko-yellow" />
        <span
            class="text-[11px] font-bold uppercase tracking-wider text-neutral-700 dark:text-neutral-300">
            Accounting
        </span>
    </div>
    <div class="p-4 space-y-3">
        <p class="text-[12px] leading-relaxed text-neutral-500 dark:text-neutral-400">
            Select the GL account that receives share payment cash. This account is debited when a member purchases shares.
        </p>
        <div>
            <label
                class="mb-2 block text-[11px] font-bold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">
                Share Payment Account (DR)
            </label>
            <SearchableSelect
                :model-value="sharePaymentAccountId"
                :options="accounts"
                placeholder="Select account (e.g. 1121 - Bank)"
                @update:model-value="emit('update:sharePaymentAccountId', $event)"
            />
            <p class="mt-1.5 text-[11px] text-neutral-400 dark:text-neutral-500">
                Leave blank to disable automatic journal entries for share purchases.
            </p>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Wire the drawer in `SharesSettings.vue`**

Open `src/tenant/modules/settings/pages/SharesSettings.vue`.

**4a.** In the `useSharesSettings()` destructure block (around line 13), add `tempSharePaymentAccountId` and `accounts` to the destructured values:

```typescript
const {
    // Shares
    sharesDrawerOpen,
    sharesDrawerSaving,
    sharesDrawerLoading,
    tempHideIsShareholderField,
    tempSharesCompulsory,
    tempMinShares,
    tempSharePrice,
    tempAppliesToExisting,
    tempSharePaymentAccountId,
    accounts,
    minInvestment,
    openSharesDrawer,
    saveSharesSettings,
    // ... rest unchanged
} = useSharesSettings()
```

**4b.** Find the `<ManageSharesDrawer` block (around line 122) and add two new bindings — `v-model:share-payment-account-id` and `:accounts`:

```vue
<ManageSharesDrawer
    v-model:show="sharesDrawerOpen"
    v-model:shares-compulsory="tempSharesCompulsory"
    v-model:min-shares="tempMinShares"
    v-model:share-price="tempSharePrice"
    v-model:applies-to-existing="tempAppliesToExisting"
    v-model:hide-is-shareholder-field="tempHideIsShareholderField"
    v-model:share-payment-account-id="tempSharePaymentAccountId"
    :loading="sharesDrawerLoading"
    :saving="sharesDrawerSaving"
    :currency-code="currencyCode"
    :min-investment="minInvestment"
    :accounts="accounts"
    :save-settings="saveSharesSettings"
/>
```

- [ ] **Step 5: Run type-check**

```bash
pnpm type-check
```

Expected: no errors.

- [ ] **Step 6: Run lint**

```bash
pnpm lint
```

Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add src/tenant/modules/settings/composables/useSharesSettings.ts \
        src/tenant/modules/settings/components/shares-dividends/ManageSharesDrawer.vue \
        src/tenant/modules/settings/pages/SharesSettings.vue
git commit -m "feat: add share payment GL account picker to Share Management Settings drawer"
```
