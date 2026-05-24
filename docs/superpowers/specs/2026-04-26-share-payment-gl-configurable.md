# Configurable Share Payment GL Account Design

## Goal

Replace the hardcoded `gl_code = '1111'` (Petty Cash) DR account in `ShareAccountingService` with an admin-configurable `share_payment_account_id` stored in `OnboardingSettings`. The CR account (`3110` — Ordinary Share Capital) remains hardcoded.

## Architecture

`OnboardingSettings` already owns share-related configuration (price, compulsory rules, etc.) and has a dedicated settings drawer in the frontend. We add a single nullable FK column `share_payment_account_id → chart_of_accounts(id)` to that table. `ShareAccountingService` reads this setting at journal-entry time instead of looking up by GL code string. If the setting is null (not yet configured), the service logs a warning and skips posting — same silent-skip behaviour as today.

## Tech Stack

- Laravel 12 (PHP 8.2), Eloquent, PostgreSQL
- Vue 3, TypeScript, Pinia, `SearchableSelect` component (already used in `LoanProductCreate.vue`)

---

## Backend

### Migration

New tenant migration: `add_share_payment_account_id_to_onboarding_settings_table`

```php
Schema::connection('tenant')->table('onboarding_settings', function (Blueprint $table) {
    $table->unsignedBigInteger('share_payment_account_id')->nullable()->after('share_price');
    $table->foreign('share_payment_account_id')
          ->references('id')->on('chart_of_accounts')
          ->onDelete('set null');
});
```

### `OnboardingSettings` model

- Add `share_payment_account_id` to `$fillable`
- Add `'share_payment_account_id' => 'integer'` to `$casts`
- Add relation: `public function sharePaymentAccount(): BelongsTo`
- Default in `current()` remains `null` (no default account assumed)

### `OnboardingSettingsRequest`

Add rule:
```php
'share_payment_account_id' => ['nullable', 'integer', 'exists:tenant.chart_of_accounts,id'],
```

### `ShareAccountingService`

Replace:
```php
$cashGl = ChartOfAccount::on('tenant')->where('gl_code', '1111')->first();
```

With:
```php
$settings = OnboardingSettings::current();
$cashGl = $settings->share_payment_account_id
    ? ChartOfAccount::on('tenant')->find($settings->share_payment_account_id)
    : null;
```

The existing null-check and `Log::warning()` remain unchanged — if the account is not configured, the JE is silently skipped with a log entry.

---

## Frontend

### `src/tenant/apis/onboardingSettings/api.ts`

Add `share_payment_account_id?: number | null` to the `OnboardingSettings` interface.

### `src/stores/settingsStore.ts`

- Add `sharePaymentAccountId` ref (type `number | null`, default `null`)
- Populate in `fetchOnboardingSettings()` from `data.share_payment_account_id`
- Include in `saveOnboardingSettings()` payload

### `src/tenant/modules/settings/composables/useSharesSettings.ts`

- Add `tempSharePaymentAccountId = ref<number | null>(null)`
- Sync from `settingsStore.sharePaymentAccountId` in `openSharesDrawer()`
- Include in `saveOnboardingSettings()` call: `share_payment_account_id: tempSharePaymentAccountId.value`

### `src/tenant/modules/settings/components/shares-dividends/ManageSharesDrawer.vue`

- Add `sharePaymentAccountId` and `accounts` to the component props
- Add `update:sharePaymentAccountId` to `defineEmits`
- Add `accounts` ref (type `{ value: number; label: string }[]`, default `[]`) to the composable
- In `openSharesDrawer()`, call `chartOfAccountsApi.list()` and map results to `{ value: account.id, label: \`\${account.gl_code} – \${account.name}\` }`, stored in `accounts`
- Pass `accounts` as a prop to `ManageSharesDrawer`
- Add a new "Accounting" section in the drawer body with a `SearchableSelect`:

```vue
<SearchableSelect
  :value="sharePaymentAccountId"
  :options="accounts"
  placeholder="Select share payment account"
  @update:modelValue="emit('update:sharePaymentAccountId', $event)"
/>
```

Options format: `{ value: account.id, label: \`\${account.gl_code} – \${account.name}\` }` — same as `LoanProductCreate.vue`.

---

## Error Handling

- If `share_payment_account_id` is null: `Log::warning()` + skip JE (existing behaviour, unchanged)
- If the FK row is deleted from `chart_of_accounts`: DB `onDelete('set null')` resets the column to null automatically, triggering the skip path on next purchase
- Validation in `OnboardingSettingsRequest` prevents saving a non-existent account ID

## Testing

- **Backend unit test** (`tests/Feature/Tenant/Shares/SharePurchaseAccountingTest.php` — existing file): add a test case that sets `share_payment_account_id` on `OnboardingSettings` and asserts the JE DR line hits that account's id (not GL `1111`)
- **Backend skip test**: assert that when `share_payment_account_id` is null, no JE is created (already covered by existing skip test — just verify it still passes)
- No new frontend unit tests required (settings store/composable wiring is trivial plumbing)
