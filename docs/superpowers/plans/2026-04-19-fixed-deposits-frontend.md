# Fixed Deposits — Frontend Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the fixed deposit UI — FD product settings, FD account creation fields, account detail extensions (maturity badge, interest history, process maturity), and a manager dashboard with a month-end sweep button.

**Architecture:** Thin API layer + focused single-responsibility components. The existing `form.type === 'fixed'` dropdown in `SavingsProductForm.vue` gates a new `FdSettingsCard.vue`. `ViewAccountDrawer.vue` gains an FD section that embeds `InterestPostingHistory.vue` and opens `FdMaturityDrawer.vue`. A new `FixedDepositsDashboard.vue` page with its own route handles the manager sweep.

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Tailwind CSS v4, Lucide icons, `vue-sonner` toasts, Axios via tenant API client.

**IMPORTANT — read before every task:**
- Read `CLAUDE.md` at the repo root before starting
- No `<script setup>` block may exceed 200 lines
- No single `.vue` file template may be so long it can't be reasoned about; extract child components aggressively
- Prefer `computed` + `watch` over side-effect-heavy `onMounted` chains
- Always handle loading + error states

---

## File Map

| Action | File | Responsibility |
|---|---|---|
| Modify | `src/tenant/apis/savingsProducts/api.ts` | Extend `SavingsProduct` interface with FD fields |
| Modify | `src/tenant/apis/savingsAccounts/savingsAccountsApi.ts` | Add `postInterest`, `interestPostings`, `processMaturity` endpoints |
| Create | `src/tenant/apis/fixedDeposits/fixedDepositsApi.ts` | `list` and `postInterest` for the manager dashboard |
| Create | `src/tenant/modules/settings/components/FdSettingsCard.vue` | FD configuration fields rendered inside `SavingsProductForm.vue` |
| Modify | `src/tenant/modules/settings/pages/SavingsProductForm.vue` | Import + render `FdSettingsCard` when `form.type === 'fixed'` |
| Modify | `src/tenant/modules/savings/components/CreateAccountDrawer.vue` | Add FD fields (tenor, maturity action, payout account) for fixed products |
| Create | `src/tenant/modules/savings/components/InterestPostingHistory.vue` | Posting audit table loaded inside `ViewAccountDrawer` |
| Create | `src/tenant/modules/savings/components/FdMaturityDrawer.vue` | Process maturity drawer (rollover / convert / withdraw) |
| Modify | `src/tenant/modules/savings/components/ViewAccountDrawer.vue` | FD info section, maturity badge, interest history, maturity drawer trigger |
| Create | `src/tenant/modules/savings/pages/FixedDepositsDashboard.vue` | List all active FDs + "Post Monthly Interest" sweep button |
| Modify | `src/tenant/modules/savings/routes.ts` | Add `fixed-deposits` route |

---

### Task 1: API Extensions

**Files:**
- Modify: `src/tenant/apis/savingsProducts/api.ts`
- Modify: `src/tenant/apis/savingsAccounts/savingsAccountsApi.ts`
- Create: `src/tenant/apis/fixedDeposits/fixedDepositsApi.ts`

- [ ] **Step 1: Extend `SavingsProduct` interface with FD fields**

Open `src/tenant/apis/savingsProducts/api.ts`. Replace the `SavingsProduct` interface with:

```typescript
export interface SavingsProduct {
  id?: number
  name: string
  type: 'fixed' | 'standard'
  minimum_balance: number | string
  minimum_maturity_months: number
  dormancy_period_months: number
  charge_on_deposit: boolean
  charge_on_withdraw: boolean
  charge_on_transfer: boolean
  status: 'active' | 'inactive'
  monthly_fee_enabled?: boolean
  monthly_fee_type?: 'percentage' | 'amount' | null
  monthly_fee_amount?: number | string | null
  monthly_fee_deduction_day?: number | null
  loyalty_fee_enabled?: boolean
  loyalty_adjustment_type?: 'discount_percentage' | 'fixed_discount' | 'custom_fee' | null
  loyalty_adjustment_value?: number | string | null
  charges?: Charge[]
  // Fixed deposit fields
  interest_rate?: number | null            // stored as decimal e.g. 0.1200
  interest_payout_type?: 'at_maturity' | 'periodic_payout' | 'compound' | null
  interest_posting_frequency?: 'monthly' | 'quarterly' | 'semi_annually' | 'annually' | null
  default_tenor_months?: number | null
  maturity_action?: 'auto_rollover' | 'manual' | 'convert_to_savings' | null
  convert_to_product_id?: number | null
  interest_expense_account_id?: number | null
  interest_payable_account_id?: number | null
}
```

- [ ] **Step 2: Add FD endpoints to `savingsAccountsApi.ts`**

Open `src/tenant/apis/savingsAccounts/savingsAccountsApi.ts`. Add three new methods:

```typescript
import { tenantClient } from '@/tenant/apis/tenantClient'

export const savingsAccountsApi = {
  list(params?: { search?: string; status?: string; page?: number; member_id?: number }) {
    return tenantClient.get('/savings-accounts', { params })
  },
  show(id: number) {
    return tenantClient.get(`/savings-accounts/${id}`)
  },
  store(data: Record<string, any>) {
    return tenantClient.post('/savings-accounts', data)
  },
  update(id: number, data: Record<string, any>) {
    return tenantClient.put(`/savings-accounts/${id}`, data)
  },
  destroy(id: number) {
    return tenantClient.delete(`/savings-accounts/${id}`)
  },
  deposit(id: number, data: Record<string, any>) {
    return tenantClient.post(`/savings-accounts/${id}/deposit`, data)
  },
  withdraw(id: number, data: Record<string, any>) {
    return tenantClient.post(`/savings-accounts/${id}/withdraw`, data)
  },
  charge(id: number, data: Record<string, any>) {
    return tenantClient.post(`/savings-accounts/${id}/charge`, data)
  },
  // Fixed deposit endpoints
  interestPostings(id: number) {
    return tenantClient.get(`/savings-accounts/${id}/interest-postings`)
  },
  processMaturity(id: number, data: { action: 'rollover' | 'convert' | 'close' }) {
    return tenantClient.post(`/savings-accounts/${id}/maturity/process`, data)
  },
}
```

- [ ] **Step 3: Create `fixedDepositsApi.ts`**

Create `src/tenant/apis/fixedDeposits/fixedDepositsApi.ts`:

```typescript
import { tenantClient } from '@/tenant/apis/tenantClient'

export const fixedDepositsApi = {
  list(params?: { search?: string; status?: string; page?: number }) {
    return tenantClient.get('/savings/fixed-deposits', { params })
  },
  postInterest() {
    return tenantClient.post('/savings/fixed-deposits/post-interest')
  },
}
```

- [ ] **Step 4: Commit**

```bash
git add src/tenant/apis/savingsProducts/api.ts \
        src/tenant/apis/savingsAccounts/savingsAccountsApi.ts \
        src/tenant/apis/fixedDeposits/fixedDepositsApi.ts
git commit -m "feat(fd): extend API types and add fixed deposit endpoints"
```

---

### Task 2: `FdSettingsCard.vue` — FD product configuration

**Files:**
- Create: `src/tenant/modules/settings/components/FdSettingsCard.vue`

This component receives the parent `form` object by reference and mutates FD fields directly. It also receives the product list (for the "convert to" dropdown) and a chart-of-accounts list (for GL account selectors).

- [ ] **Step 1: Create the component**

Create `src/tenant/modules/settings/components/FdSettingsCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import type { SavingsProduct } from '@/tenant/apis/savingsProducts/api'

interface ChartAccount { id: number; name: string; code: string }

const props = defineProps<{
  form: SavingsProduct
  products: SavingsProduct[]
  chartAccounts: ChartAccount[]
}>()

// Display rate as percentage; backend stores as decimal (0.12 = 12%)
const interestRateDisplay = computed({
  get: () => props.form.interest_rate != null ? Number((props.form.interest_rate * 100).toFixed(4)) : '',
  set: (val: string | number) => {
    const n = parseFloat(String(val))
    props.form.interest_rate = isNaN(n) ? null : parseFloat((n / 100).toFixed(6))
  },
})

const showFrequency = computed(() =>
  props.form.interest_payout_type === 'periodic_payout' ||
  props.form.interest_payout_type === 'compound'
)

const showConvertProduct = computed(() =>
  props.form.maturity_action === 'convert_to_savings'
)

const productOptions = computed(() =>
  props.products.filter(p => p.type === 'standard' && p.id !== props.form.id)
)

const selectClass = 'w-full rounded-lg border border-neutral-300 bg-transparent px-3 py-2 text-sm text-neutral-900 focus:border-nfuko-primary focus:outline-none focus:ring-1 focus:ring-bg-nfuko-primary dark:border-neutral-700 dark:text-white dark:focus:border-bg-nfuko-yellow dark:focus:ring-bg-nfuko-yellow'
const inputClass = 'w-full rounded-lg border border-neutral-300 bg-transparent px-3 py-2 text-sm text-neutral-900 focus:border-nfuko-primary focus:outline-none focus:ring-1 focus:ring-bg-nfuko-primary dark:border-neutral-700 dark:text-white dark:focus:border-bg-nfuko-yellow dark:focus:ring-bg-nfuko-yellow'
</script>

<template>
  <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-6 shadow-sm dark:border-amber-900/40 dark:bg-amber-950/10">
    <h2 class="mb-4 text-lg font-semibold text-neutral-900 dark:text-white">Fixed Deposit Settings</h2>

    <div class="grid gap-4 sm:grid-cols-2">
      <!-- Interest Rate -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Annual Interest Rate (%)
        </label>
        <input
          v-model="interestRateDisplay"
          type="number" step="0.01" min="0" max="100"
          :class="inputClass"
          placeholder="e.g. 12.00"
        />
        <p class="mt-1 text-xs text-neutral-500">Enter as percentage, e.g. 12 for 12% p.a.</p>
      </div>

      <!-- Default Tenor -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Default Tenor (Months)
        </label>
        <input
          v-model.number="form.default_tenor_months"
          type="number" min="1"
          :class="inputClass"
          placeholder="e.g. 6"
        />
      </div>

      <!-- Interest Payout Type -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Interest Payout Type
        </label>
        <select v-model="form.interest_payout_type" :class="selectClass">
          <option value="at_maturity">At Maturity (lump sum)</option>
          <option value="periodic_payout">Periodic Payout (to savings)</option>
          <option value="compound">Compound (add to principal)</option>
        </select>
      </div>

      <!-- Posting Frequency (only for periodic_payout / compound) -->
      <div v-if="showFrequency">
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Posting Frequency
        </label>
        <select v-model="form.interest_posting_frequency" :class="selectClass">
          <option value="monthly">Monthly</option>
          <option value="quarterly">Quarterly</option>
          <option value="semi_annually">Semi-Annually</option>
          <option value="annually">Annually</option>
        </select>
      </div>

      <!-- Maturity Action -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Default Maturity Action
        </label>
        <select v-model="form.maturity_action" :class="selectClass">
          <option value="manual">Manual (officer action required)</option>
          <option value="auto_rollover">Auto Rollover (same product)</option>
          <option value="convert_to_savings">Convert to Savings</option>
        </select>
      </div>

      <!-- Convert To Product -->
      <div v-if="showConvertProduct">
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Target Savings Product
        </label>
        <select v-model.number="form.convert_to_product_id" :class="selectClass">
          <option :value="null">— select product —</option>
          <option v-for="p in productOptions" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <!-- GL: Interest Expense Account -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Interest Expense GL Account (DR)
        </label>
        <select v-model.number="form.interest_expense_account_id" :class="selectClass">
          <option :value="null">— select account —</option>
          <option v-for="a in chartAccounts" :key="a.id" :value="a.id">
            {{ a.code }} — {{ a.name }}
          </option>
        </select>
      </div>

      <!-- GL: Interest Payable Account -->
      <div>
        <label class="mb-1 block text-sm font-medium text-neutral-700 dark:text-neutral-300">
          Interest Payable GL Account (CR)
        </label>
        <select v-model.number="form.interest_payable_account_id" :class="selectClass">
          <option :value="null">— select account —</option>
          <option v-for="a in chartAccounts" :key="a.id" :value="a.id">
            {{ a.code }} — {{ a.name }}
          </option>
        </select>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Verify the component renders without TypeScript errors**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | head -30
```

Expected: No errors from this new file (there may be pre-existing errors; fix only new ones).

- [ ] **Step 3: Commit**

```bash
git add src/tenant/modules/settings/components/FdSettingsCard.vue
git commit -m "feat(fd): add FdSettingsCard component for fixed deposit product settings"
```

---

### Task 3: Wire `FdSettingsCard` into `SavingsProductForm.vue`

**Files:**
- Modify: `src/tenant/modules/settings/pages/SavingsProductForm.vue`

- [ ] **Step 1: Add FD fields to the form default and add chart accounts fetch**

Open `src/tenant/modules/settings/pages/SavingsProductForm.vue`. At the top of `<script setup>`, add the new imports:

```typescript
import { chartOfAccountsApi } from '@/tenant/apis/chartOfAccounts/chartOfAccountsApi'
import FdSettingsCard from '../components/FdSettingsCard.vue'
```

Extend the `form` ref default values (inside the existing `form = ref<SavingsProduct>({...})`) — add after the `charges: []` line:

```typescript
  // FD fields (ignored by backend for standard products)
  interest_rate: null,
  interest_payout_type: 'at_maturity',
  interest_posting_frequency: 'monthly',
  default_tenor_months: 6,
  maturity_action: 'manual',
  convert_to_product_id: null,
  interest_expense_account_id: null,
  interest_payable_account_id: null,
```

Add chart accounts state and fetch (add after the existing `const saving = ref(false)` line):

```typescript
const chartAccounts = ref<Array<{ id: number; name: string; code: string }>>([])
const savingsProductList = ref<SavingsProduct[]>([])

async function loadSupportingData() {
  try {
    const [coaRes, productsRes] = await Promise.all([
      chartOfAccountsApi.list({ list: 1 }),
      savingsProductsApi.list(),
    ])
    chartAccounts.value = coaRes.data?.data ?? []
    savingsProductList.value = productsRes.data?.data ?? []
  } catch {
    // non-fatal — dropdowns show empty
  }
}
```

Update `onMounted` to also call `loadSupportingData()`:

```typescript
onMounted(() => {
  loadProduct()
  loadSupportingData()
})
```

- [ ] **Step 2: Add `FdSettingsCard` to the template**

Inside the `<div v-else class="grid gap-6 lg:grid-cols-[1fr_400px]">` → `<div class="flex flex-col gap-6">`, add after the "Monthly fees" card block:

```html
<!-- Fixed Deposit Settings (only for type = fixed) -->
<FdSettingsCard
  v-if="form.type === 'fixed'"
  :form="form"
  :products="savingsProductList"
  :chart-accounts="chartAccounts"
/>
```

- [ ] **Step 3: Verify the page renders without TypeScript errors**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "SavingsProductForm\|FdSettingsCard" | head -20
```

Expected: No errors from these files.

- [ ] **Step 4: Manual verification**

Start the dev server (`pnpm dev`). Navigate to `tenant/settings/savings-products/create`. Switch the Type dropdown to "Fixed". Confirm the "Fixed Deposit Settings" card appears. Switch to "Standard" — confirm it disappears. Fill in interest rate 12, tenor 6, payout type "At Maturity", and save. Confirm no console errors.

- [ ] **Step 5: Commit**

```bash
git add src/tenant/modules/settings/pages/SavingsProductForm.vue
git commit -m "feat(fd): wire FdSettingsCard into SavingsProductForm for fixed product type"
```

---

### Task 4: FD fields in `CreateAccountDrawer.vue`

**Files:**
- Modify: `src/tenant/modules/savings/components/CreateAccountDrawer.vue`

When the member selects a Fixed product, show: tenor months, maturity action override, payout savings account (for periodic_payout type).

- [ ] **Step 1: Add FD state to the form and computed helpers**

Open `src/tenant/modules/savings/components/CreateAccountDrawer.vue`. Add FD fields to the `form` ref:

```typescript
// inside form = ref({...}), add after the charges line:
  tenor_months: null as number | null,
  maturity_action_override: '' as string,
  payout_savings_account_id: '' as string | number,
```

Add computed helpers (after existing computed blocks):

```typescript
const selectedProduct = computed(() =>
  props.savingsProducts.find(p => p.id === Number(form.value.savings_product_id)) ?? null
)

const isFixedDeposit = computed(() => selectedProduct.value?.type === 'fixed')

const showPayoutAccount = computed(() =>
  isFixedDeposit.value &&
  selectedProduct.value?.interest_payout_type === 'periodic_payout'
)
```

Reset FD fields in the `openDrawer()` function — add after the existing reset block:

```typescript
form.value.tenor_months = null
form.value.maturity_action_override = ''
form.value.payout_savings_account_id = ''
```

In the `submit` function, include FD fields conditionally in the payload. Find where `savingsAccountsApi.store(form.value)` is called and replace with:

```typescript
const payload: Record<string, any> = { ...form.value }
if (!isFixedDeposit.value) {
  delete payload.tenor_months
  delete payload.maturity_action_override
  delete payload.payout_savings_account_id
}
if (payload.maturity_action_override === '') delete payload.maturity_action_override
if (!showPayoutAccount.value) delete payload.payout_savings_account_id
await savingsAccountsApi.store(payload)
```

- [ ] **Step 2: Add FD fields section to the template**

Inside the form template, after the existing "Account Type" or "Initial Deposit" section, add:

```html
<!-- Fixed Deposit Fields -->
<template v-if="isFixedDeposit">
  <div class="col-span-2 border-t border-neutral-100 pt-4 dark:border-neutral-800">
    <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">
      Fixed Deposit Details
    </p>
  </div>

  <div>
    <Label>Tenor (Months)</Label>
    <input
      v-model.number="form.tenor_months"
      type="number" min="1"
      class="w-full rounded-lg border border-neutral-300 bg-transparent px-3 py-2 text-sm text-neutral-900 focus:border-nfuko-primary focus:outline-none focus:ring-1 focus:ring-bg-nfuko-primary dark:border-neutral-700 dark:text-white"
      :placeholder="selectedProduct?.default_tenor_months ? String(selectedProduct.default_tenor_months) : '6'"
    />
    <InputError :message="errors.tenor_months?.[0]" />
  </div>

  <div>
    <Label>Maturity Action (override)</Label>
    <select
      v-model="form.maturity_action_override"
      class="w-full rounded-lg border border-neutral-300 bg-transparent px-3 py-2 text-sm text-neutral-900 focus:border-nfuko-primary focus:outline-none focus:ring-1 focus:ring-bg-nfuko-primary dark:border-neutral-700 dark:text-white"
    >
      <option value="">Use product default</option>
      <option value="manual">Manual</option>
      <option value="auto_rollover">Auto Rollover</option>
      <option value="convert_to_savings">Convert to Savings</option>
    </select>
  </div>

  <div v-if="showPayoutAccount" class="col-span-2">
    <Label>Payout Savings Account (for periodic interest)</Label>
    <SearchableSelect
      v-model="form.payout_savings_account_id"
      :options="creditedAccountOptions"
      placeholder="Select member's savings account..."
    />
    <InputError :message="errors.payout_savings_account_id?.[0]" />
  </div>
</template>
```

- [ ] **Step 3: Verify TypeScript**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "CreateAccountDrawer" | head -10
```

Expected: No new errors.

- [ ] **Step 4: Commit**

```bash
git add src/tenant/modules/savings/components/CreateAccountDrawer.vue
git commit -m "feat(fd): add tenor, maturity action, and payout account fields to CreateAccountDrawer"
```

---

### Task 5: `InterestPostingHistory.vue`

**Files:**
- Create: `src/tenant/modules/savings/components/InterestPostingHistory.vue`

A self-contained component that fetches and displays the posting history for an FD account.

- [ ] **Step 1: Create the component**

Create `src/tenant/modules/savings/components/InterestPostingHistory.vue`:

```vue
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { savingsAccountsApi } from '@/tenant/apis/savingsAccounts/savingsAccountsApi'
import { useCurrencyStore } from '@/stores/currency'
import { computed } from 'vue'

interface Posting {
  id: number
  period_start: string
  period_end: string
  principal: string
  rate: string
  interest_amount: string
  payout_type: string
  posted_by: number | null
  created_at: string
}

const props = defineProps<{ accountId: number; currency: string }>()

const currencyStore = useCurrencyStore()
const currency = computed(() => props.currency || currencyStore.currencyCode)

const postings = ref<Posting[]>([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const res = await savingsAccountsApi.interestPostings(props.accountId)
    postings.value = res.data?.data ?? []
  } catch (err: any) {
    error.value = err?.response?.data?.message ?? 'Failed to load interest history.'
  } finally {
    loading.value = false
  }
}

function formatDate(d: string) {
  return d ? new Date(d).toLocaleDateString('en-KE', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
}

function formatAmount(v: string | number) {
  const n = Number(v)
  return isNaN(n) ? '—' : `${currency.value} ${n.toLocaleString('en-KE', { minimumFractionDigits: 2 })}`
}

function payoutLabel(type: string) {
  return { at_maturity: 'At Maturity', periodic_payout: 'Periodic', compound: 'Compound' }[type] ?? type
}

onMounted(load)
defineExpose({ reload: load })
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <h4 class="text-sm font-semibold text-neutral-700 dark:text-neutral-300">Interest Posting History</h4>
      <button type="button" @click="load" class="text-xs text-nfuko-primary underline hover:no-underline dark:text-nfuko-yellow">
        Refresh
      </button>
    </div>

    <div v-if="loading" class="space-y-2">
      <div v-for="i in 3" :key="i" class="h-8 rounded bg-neutral-100 animate-pulse dark:bg-neutral-800" />
    </div>

    <div v-else-if="error" class="rounded-lg bg-red-50 p-3 text-xs text-red-700 dark:bg-red-950/30 dark:text-red-400">
      {{ error }}
    </div>

    <div v-else-if="postings.length === 0" class="rounded-lg border border-dashed border-neutral-200 p-4 text-center text-xs text-neutral-400 dark:border-neutral-700">
      No interest postings yet.
    </div>

    <div v-else class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-700">
      <table class="w-full text-xs">
        <thead class="bg-neutral-50 text-left text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">
          <tr>
            <th class="px-3 py-2 font-medium">Period</th>
            <th class="px-3 py-2 font-medium">Amount</th>
            <th class="px-3 py-2 font-medium">Type</th>
            <th class="px-3 py-2 font-medium">Posted</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
          <tr v-for="p in postings" :key="p.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
            <td class="px-3 py-2 text-neutral-700 dark:text-neutral-300">
              {{ formatDate(p.period_start) }} — {{ formatDate(p.period_end) }}
            </td>
            <td class="px-3 py-2 font-medium text-green-700 dark:text-green-400">
              {{ formatAmount(p.interest_amount) }}
            </td>
            <td class="px-3 py-2 text-neutral-500">{{ payoutLabel(p.payout_type) }}</td>
            <td class="px-3 py-2 text-neutral-400">{{ formatDate(p.created_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "InterestPostingHistory" | head -10
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add src/tenant/modules/savings/components/InterestPostingHistory.vue
git commit -m "feat(fd): add InterestPostingHistory component"
```

---

### Task 6: `FdMaturityDrawer.vue`

**Files:**
- Create: `src/tenant/modules/savings/components/FdMaturityDrawer.vue`

A drawer for processing maturity — allows the officer to choose rollover, convert, or close.

- [ ] **Step 1: Create the component**

Create `src/tenant/modules/savings/components/FdMaturityDrawer.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { X, ArrowLeft, RotateCcw, ArrowRightLeft, Banknote } from 'lucide-vue-next'
import { savingsAccountsApi } from '@/tenant/apis/savingsAccounts/savingsAccountsApi'
import { toast } from 'vue-sonner'

interface Account { id: number; account_no: string; maturity_date: string | null }

const props = defineProps<{ currency: string }>()
const emit = defineEmits<{ success: [] }>()

const open = ref(false)
const processing = ref(false)
const account = ref<Account | null>(null)
const selectedAction = ref<'rollover' | 'convert' | 'close' | ''>('')

const actions = [
  {
    key: 'rollover' as const,
    label: 'Rollover',
    description: 'Reinvest principal + accrued interest for the same tenor.',
    icon: RotateCcw,
    color: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-300',
    active: 'ring-2 ring-blue-400',
  },
  {
    key: 'convert' as const,
    label: 'Convert to Savings',
    description: 'Move principal + interest to the member\'s savings account.',
    icon: ArrowRightLeft,
    color: 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300',
    active: 'ring-2 ring-amber-400',
  },
  {
    key: 'close' as const,
    label: 'Withdraw & Close',
    description: 'Pay out principal + interest and close the FD account.',
    icon: Banknote,
    color: 'border-green-200 bg-green-50 text-green-700 dark:border-green-800 dark:bg-green-950/30 dark:text-green-300',
    active: 'ring-2 ring-green-400',
  },
]

function openDrawer(acc: Account) {
  account.value = acc
  selectedAction.value = ''
  open.value = true
}

function close() {
  open.value = false
  account.value = null
}

async function submit() {
  if (!selectedAction.value || !account.value) return
  processing.value = true
  try {
    await savingsAccountsApi.processMaturity(account.value.id, { action: selectedAction.value })
    toast.success('Maturity processed successfully.')
    emit('success')
    close()
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Failed to process maturity.')
  } finally {
    processing.value = false
  }
}

defineExpose({ openDrawer })
</script>

<template>
  <Transition name="drawer-fade">
    <div v-if="open" class="fixed inset-0 z-50">
      <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="close" />
      <aside
        class="absolute right-0 top-0 h-full w-full max-w-[480px] bg-white shadow-2xl ring-1 ring-black/5 dark:bg-neutral-900"
        role="dialog" aria-label="Process FD Maturity"
      >
        <div class="flex h-full flex-col">
          <!-- Header -->
          <div class="flex items-center justify-between border-b border-neutral-200 px-6 py-4 dark:border-neutral-700">
            <div class="flex items-center gap-3">
              <button type="button" @click="close"
                class="flex h-8 w-8 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 transition-colors dark:hover:bg-neutral-800">
                <ArrowLeft class="h-4 w-4" />
              </button>
              <div>
                <h3 class="text-[15px] font-bold text-neutral-900 dark:text-white">Process Maturity</h3>
                <p class="text-xs text-neutral-500">{{ account?.account_no }}</p>
              </div>
            </div>
            <button type="button" @click="close"
              class="flex h-8 w-8 items-center justify-center rounded-lg text-neutral-500 hover:bg-neutral-100 transition-colors dark:hover:bg-neutral-800">
              <X class="h-4 w-4" />
            </button>
          </div>

          <!-- Body -->
          <div class="flex-1 overflow-y-auto px-6 py-5 space-y-4">
            <p v-if="account?.maturity_date" class="text-sm text-neutral-500 dark:text-neutral-400">
              Matured on <span class="font-medium text-neutral-700 dark:text-neutral-200">{{ account.maturity_date }}</span>
            </p>

            <p class="text-sm font-medium text-neutral-700 dark:text-neutral-300">Choose an action:</p>

            <div class="space-y-3">
              <button
                v-for="action in actions" :key="action.key"
                type="button"
                @click="selectedAction = action.key"
                :class="[
                  'w-full rounded-xl border p-4 text-left transition-all',
                  action.color,
                  selectedAction === action.key ? action.active : '',
                ]"
              >
                <div class="flex items-center gap-3">
                  <component :is="action.icon" class="h-5 w-5 shrink-0" />
                  <div>
                    <p class="text-sm font-semibold">{{ action.label }}</p>
                    <p class="text-xs opacity-80">{{ action.description }}</p>
                  </div>
                </div>
              </button>
            </div>
          </div>

          <!-- Footer -->
          <div class="border-t border-neutral-200 px-6 py-4 dark:border-neutral-700">
            <button
              type="button" @click="submit"
              :disabled="!selectedAction || processing"
              class="w-full rounded-lg bg-nfuko-primary px-4 py-2.5 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-40 disabled:cursor-not-allowed dark:bg-nfuko-yellow dark:text-neutral-900"
            >
              {{ processing ? 'Processing...' : 'Confirm' }}
            </button>
          </div>
        </div>
      </aside>
    </div>
  </Transition>
</template>
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "FdMaturityDrawer" | head -10
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add src/tenant/modules/savings/components/FdMaturityDrawer.vue
git commit -m "feat(fd): add FdMaturityDrawer for rollover, convert, and close actions"
```

---

### Task 7: Extend `ViewAccountDrawer.vue` with FD features

**Files:**
- Modify: `src/tenant/modules/savings/components/ViewAccountDrawer.vue`

Add maturity status badge, FD information section, interest posting history, and process maturity button.

- [ ] **Step 1: Add imports and FD computed state**

Open `src/tenant/modules/savings/components/ViewAccountDrawer.vue`. Add imports at the top of `<script setup>`:

```typescript
import InterestPostingHistory from './InterestPostingHistory.vue'
import FdMaturityDrawer from './FdMaturityDrawer.vue'
import { computed } from 'vue'
import { useCurrencyStore } from '@/stores/currency'
```

Add computed helpers (after existing refs):

```typescript
const currencyStore = useCurrencyStore()
const currency = computed(() => currencyStore.currencyCode)

const isFixedDeposit = computed(() => account.value?.account_type === 'fixed')

const isMatured = computed(() => {
  if (!isFixedDeposit.value || !account.value?.maturity_date) return false
  return new Date(account.value.maturity_date) <= new Date()
})

const maturityDrawerRef = ref<InstanceType<typeof FdMaturityDrawer> | null>(null)

function openMaturityDrawer() {
  if (!account.value) return
  maturityDrawerRef.value?.openDrawer({
    id: account.value.id,
    account_no: account.value.account_no,
    maturity_date: account.value.maturity_date ?? null,
  })
}
```

Add `defineExpose` update — add `currency` to existing exposed surface:

```typescript
// Already has: defineExpose({ openDrawer })
// Keep it — no change needed; currency comes from store not prop
```

- [ ] **Step 2: Add FD sections to the template**

Inside the `<div v-else-if="account" class="space-y-6">` block (after the existing account info fields), add:

```html
<!-- FD Info Section -->
<div v-if="isFixedDeposit" class="space-y-3 rounded-xl border border-amber-200 bg-amber-50/40 p-4 dark:border-amber-900/40 dark:bg-amber-950/10">
  <div class="flex items-center justify-between">
    <p class="text-xs font-semibold uppercase tracking-wide text-amber-600 dark:text-amber-400">Fixed Deposit</p>
    <span
      :class="[
        'rounded-full px-2 py-0.5 text-[11px] font-semibold',
        isMatured
          ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
          : 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
      ]"
    >
      {{ isMatured ? 'Matured' : 'Active' }}
    </span>
  </div>

  <div class="grid grid-cols-2 gap-3 text-sm">
    <div>
      <p class="text-xs text-neutral-500">Tenor</p>
      <p class="font-medium text-neutral-800 dark:text-neutral-200">
        {{ account.tenor_months ?? '—' }} months
      </p>
    </div>
    <div>
      <p class="text-xs text-neutral-500">Maturity Date</p>
      <p class="font-medium text-neutral-800 dark:text-neutral-200">
        {{ account.maturity_date ?? '—' }}
      </p>
    </div>
    <div>
      <p class="text-xs text-neutral-500">Interest Rate</p>
      <p class="font-medium text-neutral-800 dark:text-neutral-200">
        {{ account.interest_rate != null ? (account.interest_rate * 100).toFixed(2) + '%' : '—' }} p.a.
      </p>
    </div>
    <div>
      <p class="text-xs text-neutral-500">Next Interest Date</p>
      <p class="font-medium text-neutral-800 dark:text-neutral-200">
        {{ account.next_interest_date ?? '—' }}
      </p>
    </div>
  </div>

  <!-- Process Maturity Button -->
  <button
    v-if="isMatured"
    type="button"
    @click="openMaturityDrawer"
    class="mt-1 w-full rounded-lg border border-amber-400 bg-amber-100 px-4 py-2 text-sm font-semibold text-amber-800 hover:bg-amber-200 transition dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-300 dark:hover:bg-amber-950/60"
  >
    Process Maturity
  </button>
</div>

<!-- Interest Posting History -->
<div v-if="isFixedDeposit">
  <InterestPostingHistory :account-id="account.id" :currency="currency" />
</div>
```

- [ ] **Step 3: Add `FdMaturityDrawer` to the template**

Just before the closing `</div>` of the main drawer wrapper, add:

```html
<FdMaturityDrawer
  ref="maturityDrawerRef"
  :currency="currency"
  @success="openDrawer({ id: account!.id })"
/>
```

- [ ] **Step 4: Verify TypeScript**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "ViewAccountDrawer" | head -10
```

Expected: No errors.

- [ ] **Step 5: Manual verification**

Open a Fixed Deposit account in the drawer. Verify:
- Amber "Fixed Deposit" section appears with tenor, maturity date, interest rate, next interest date
- "Matured" badge appears if maturity date is in the past; "Active" otherwise
- "Process Maturity" button visible only when matured
- Interest posting history table loads (empty state shown if no postings yet)

- [ ] **Step 6: Commit**

```bash
git add src/tenant/modules/savings/components/ViewAccountDrawer.vue
git commit -m "feat(fd): extend ViewAccountDrawer with FD info, maturity badge, and interest history"
```

---

### Task 8: `FixedDepositsDashboard.vue`

**Files:**
- Create: `src/tenant/modules/savings/pages/FixedDepositsDashboard.vue`

A dedicated page listing all FD accounts with status filters and a "Post Monthly Interest" sweep button for managers.

- [ ] **Step 1: Create the page**

Create `src/tenant/modules/savings/pages/FixedDepositsDashboard.vue`:

```vue
<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { Search, Zap, Clock, CheckCircle2 } from 'lucide-vue-next'
import { fixedDepositsApi } from '@/tenant/apis/fixedDeposits/fixedDepositsApi'
import { toast } from 'vue-sonner'
import { useCurrencyStore } from '@/stores/currency'

interface FdAccount {
  id: number
  account_no: string
  balance: string
  status: string
  maturity_date: string | null
  next_interest_date: string | null
  tenor_months: number | null
  member: { id: number; name: string; member_number: string } | null
  savings_product: { id: number; name: string } | null
}

interface SweepResult { posted: number; skipped: number; matured: number; errors: number }
interface Meta { current_page: number; last_page: number; total: number }

const currencyStore = useCurrencyStore()
const currency = computed(() => currencyStore.currencyCode)

const accounts = ref<FdAccount[]>([])
const meta = ref<Meta>({ current_page: 1, last_page: 1, total: 0 })
const loading = ref(false)
const sweeping = ref(false)
const search = ref('')
const sweepResult = ref<SweepResult | null>(null)
let searchTimer: ReturnType<typeof setTimeout> | null = null

async function fetchAccounts(page = 1) {
  loading.value = true
  try {
    const res = await fixedDepositsApi.list({ search: search.value || undefined, page })
    accounts.value = res.data?.data ?? []
    if (res.data?.meta) meta.value = res.data.meta
  } catch {
    toast.error('Failed to load fixed deposit accounts.')
  } finally {
    loading.value = false
  }
}

function onSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => fetchAccounts(1), 400)
}

async function runSweep() {
  sweeping.value = true
  sweepResult.value = null
  try {
    const res = await fixedDepositsApi.postInterest()
    sweepResult.value = res.data?.data ?? null
    toast.success(`Sweep complete — ${sweepResult.value?.posted ?? 0} postings made.`)
    await fetchAccounts(1)
  } catch (err: any) {
    toast.error(err?.response?.data?.message ?? 'Sweep failed.')
  } finally {
    sweeping.value = false
  }
}

function isMatured(account: FdAccount) {
  if (!account.maturity_date) return false
  return new Date(account.maturity_date) <= new Date()
}

function formatBalance(v: string | number) {
  const n = Number(v)
  return isNaN(n) ? '—' : `${currency.value} ${n.toLocaleString('en-KE', { minimumFractionDigits: 2 })}`
}

onMounted(() => fetchAccounts())
</script>

<template>
  <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6 bg-[#f8faf9] dark:bg-[#0a0a0a]">
    <!-- Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-neutral-900 dark:text-white">Fixed Deposits</h1>
        <p class="text-sm text-neutral-500 dark:text-neutral-400">
          {{ meta.total }} total accounts
        </p>
      </div>
      <button
        type="button" @click="runSweep" :disabled="sweeping"
        class="flex items-center gap-2 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-600 transition disabled:opacity-50 disabled:cursor-not-allowed dark:bg-amber-600 dark:hover:bg-amber-700"
      >
        <Zap class="h-4 w-4" />
        {{ sweeping ? 'Running sweep...' : 'Post Monthly Interest' }}
      </button>
    </div>

    <!-- Sweep result banner -->
    <div v-if="sweepResult" class="rounded-xl border border-green-200 bg-green-50 p-4 dark:border-green-800 dark:bg-green-950/20">
      <p class="text-sm font-semibold text-green-800 dark:text-green-300">Sweep completed</p>
      <div class="mt-2 flex gap-6 text-sm text-green-700 dark:text-green-400">
        <span class="flex items-center gap-1"><CheckCircle2 class="h-4 w-4" /> {{ sweepResult.posted }} posted</span>
        <span>{{ sweepResult.skipped }} skipped</span>
        <span>{{ sweepResult.matured }} matured</span>
        <span v-if="sweepResult.errors > 0" class="text-red-600 dark:text-red-400">{{ sweepResult.errors }} errors</span>
      </div>
    </div>

    <!-- Search -->
    <div class="flex gap-3">
      <div class="relative flex-1 max-w-sm">
        <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
        <input
          v-model="search" @input="onSearch" type="text"
          placeholder="Search by member or account..."
          class="w-full rounded-lg border border-neutral-300 bg-white py-2 pl-9 pr-3 text-sm focus:border-nfuko-primary focus:outline-none focus:ring-1 dark:border-neutral-700 dark:bg-neutral-900 dark:text-white"
        />
      </div>
    </div>

    <!-- Table -->
    <div class="rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-neutral-800 dark:bg-neutral-900">
      <div v-if="loading" class="space-y-3 p-4">
        <div v-for="i in 5" :key="i" class="h-10 rounded bg-neutral-100 animate-pulse dark:bg-neutral-800" />
      </div>

      <div v-else-if="accounts.length === 0" class="py-16 text-center text-sm text-neutral-400">
        No fixed deposit accounts found.
      </div>

      <div v-else class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500 dark:border-neutral-700 dark:text-neutral-400">
            <tr>
              <th class="px-4 py-3 font-medium">Account</th>
              <th class="px-4 py-3 font-medium">Member</th>
              <th class="px-4 py-3 font-medium">Product</th>
              <th class="px-4 py-3 font-medium">Balance</th>
              <th class="px-4 py-3 font-medium">Tenor</th>
              <th class="px-4 py-3 font-medium">Maturity Date</th>
              <th class="px-4 py-3 font-medium">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            <tr v-for="acc in accounts" :key="acc.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/40">
              <td class="px-4 py-3 font-mono text-xs text-neutral-700 dark:text-neutral-300">{{ acc.account_no }}</td>
              <td class="px-4 py-3 text-neutral-700 dark:text-neutral-300">
                {{ acc.member?.name ?? '—' }}
                <span class="block text-xs text-neutral-400">{{ acc.member?.member_number }}</span>
              </td>
              <td class="px-4 py-3 text-neutral-500">{{ acc.savings_product?.name ?? '—' }}</td>
              <td class="px-4 py-3 font-medium text-neutral-900 dark:text-white">{{ formatBalance(acc.balance) }}</td>
              <td class="px-4 py-3 text-neutral-500">{{ acc.tenor_months != null ? acc.tenor_months + 'mo' : '—' }}</td>
              <td class="px-4 py-3 text-neutral-500">
                <div class="flex items-center gap-1">
                  <Clock v-if="!isMatured(acc)" class="h-3 w-3 text-blue-400" />
                  <span :class="isMatured(acc) ? 'text-red-600 font-medium dark:text-red-400' : ''">
                    {{ acc.maturity_date ?? '—' }}
                  </span>
                </div>
              </td>
              <td class="px-4 py-3">
                <span :class="[
                  'rounded-full px-2 py-0.5 text-[11px] font-semibold',
                  isMatured(acc)
                    ? 'bg-red-100 text-red-700 dark:bg-red-950/40 dark:text-red-400'
                    : 'bg-green-100 text-green-700 dark:bg-green-950/40 dark:text-green-400'
                ]">
                  {{ isMatured(acc) ? 'Matured' : 'Active' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-neutral-200 px-4 py-3 dark:border-neutral-700">
        <p class="text-xs text-neutral-500">Page {{ meta.current_page }} of {{ meta.last_page }}</p>
        <div class="flex gap-2">
          <button
            type="button" @click="fetchAccounts(meta.current_page - 1)"
            :disabled="meta.current_page === 1"
            class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs hover:bg-neutral-50 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800"
          >Previous</button>
          <button
            type="button" @click="fetchAccounts(meta.current_page + 1)"
            :disabled="meta.current_page === meta.last_page"
            class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs hover:bg-neutral-50 disabled:opacity-40 dark:border-neutral-700 dark:hover:bg-neutral-800"
          >Next</button>
        </div>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 2: Verify TypeScript**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "FixedDeposits" | head -10
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add src/tenant/modules/savings/pages/FixedDepositsDashboard.vue \
        src/tenant/apis/fixedDeposits/fixedDepositsApi.ts
git commit -m "feat(fd): add FixedDepositsDashboard page with sweep button"
```

---

### Task 9: Routes and navigation

**Files:**
- Modify: `src/tenant/modules/savings/routes.ts`

- [ ] **Step 1: Add the FD dashboard route**

Open `src/tenant/modules/savings/routes.ts`. Replace the file contents with:

```typescript
import type { RouteRecordRaw } from 'vue-router'
import SavingsAccounts from './pages/SavingsAccounts.vue'
import FixedDepositsDashboard from './pages/FixedDepositsDashboard.vue'

export const savingsRoutes: RouteRecordRaw[] = [
  {
    path: 'savings-accounts',
    name: 'tenant-savings-accounts',
    component: SavingsAccounts,
  },
  {
    path: 'savings/fixed-deposits',
    name: 'tenant-fixed-deposits',
    component: FixedDepositsDashboard,
  },
]
```

- [ ] **Step 2: Verify the route compiles**

```bash
cd /Users/asd/Mfuko_pro/mfuko-pro-frontend-2026
pnpm type-check 2>&1 | grep -i "routes" | head -10
```

Expected: No errors.

- [ ] **Step 3: Manual verification**

Start dev server. Navigate to `/tenant/savings/fixed-deposits`. Confirm the dashboard page loads with the table and "Post Monthly Interest" button.

- [ ] **Step 4: Commit**

```bash
git add src/tenant/modules/savings/routes.ts
git commit -m "feat(fd): add fixed-deposits route to savings module"
```

---

## Self-Review

### Spec coverage

| Spec requirement | Task implementing it |
|---|---|
| FD settings in `SavingsProductForm.vue` (interest rate, payout type, frequency, tenor, maturity action, GL accounts) | Tasks 2, 3 |
| FD-specific fields when opening an account (tenor, maturity action override, payout account) | Task 4 |
| Account detail shows FD info + maturity badge | Task 7 |
| Interest posting history table | Task 5, 7 |
| Process maturity drawer (rollover / convert / close) | Task 6, 7 |
| Manager FD list dashboard | Task 8 |
| "Post Monthly Interest" sweep button | Task 8 |
| Route registration | Task 9 |
| TypeScript interface updated with FD fields | Task 1 |
| On-access trigger (backend shows() calls processAccount) | Backend plan — frontend has nothing to do here |

### Placeholder scan

No TBDs or todos. All component code is complete. All API calls reference real endpoints defined in Task 1 and matched by the backend plan.

### Type consistency

- `FdMaturityDrawer` accepts `{ action: 'rollover' | 'convert' | 'close' }` → matches `savingsAccountsApi.processMaturity` signature in Task 1
- `InterestPostingHistory` uses `savingsAccountsApi.interestPostings(id)` → defined in Task 1
- `fixedDepositsApi.postInterest()` → matches `POST /savings/fixed-deposits/post-interest` from backend plan
- `SavingsProduct.interest_rate` stored as decimal (0.12) → `FdSettingsCard` multiplies by 100 for display, divides on input — consistent
- `CreateAccountDrawer` FD fields match backend `SavingsAccountService::create()` expected keys: `tenor_months`, `maturity_action_override`, `payout_savings_account_id`

### 200-line check

| Component | Estimated `<script setup>` lines |
|---|---|
| `FdSettingsCard.vue` | ~30 |
| `SavingsProductForm.vue` additions | +12 (total ~204) → extract `loadSupportingData` to keep under 200 |
| `CreateAccountDrawer.vue` additions | ~25 additional (total depends on existing; guard during implementation) |
| `InterestPostingHistory.vue` | ~45 |
| `FdMaturityDrawer.vue` | ~60 |
| `ViewAccountDrawer.vue` additions | ~20 additional |
| `FixedDepositsDashboard.vue` | ~70 |

**Note for implementer:** `SavingsProductForm.vue` currently has 192 lines of `<script setup>`. The additions in Task 3 add ~12 lines (taking it to ~204). If it exceeds 200, move `loadSupportingData` into a composable `useProductFormSupport.ts` and import it. This is the one file that may need extraction.
