<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Models\Scopes\BranchReadScope;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class SavingsAccountService
{
    public function __construct(
        protected SavingsJournalService $savingsJournal,
    ) {}

    /**
     * List savings accounts.
     */
    public function list(array $filters = [], int $perPage = 10): LengthAwarePaginator
    {
        $query = SavingsAccount::with(['member', 'savingsProduct'])
            ->orderBy('created_at', 'desc');

        // When filtering by a specific member (e.g. for loan disbursement),
        // bypass the branch scope — accounts follow the member, not the branch.
        if (! empty($filters['member_id'])) {
            $query->withoutGlobalScope(BranchReadScope::class);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('account_no', 'like', "%{$filters['search']}%")
                    ->orWhereHas('member', function ($mq) use ($filters) {
                        $mq->where('name', 'like', "%{$filters['search']}%")
                            ->orWhere('member_number', 'like', "%{$filters['search']}%");
                    });
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['member_id'])) {
            $query->where('member_id', $filters['member_id']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a new savings account.
     */
    public function create(array $data): SavingsAccount
    {
        return DB::connection('tenant')->transaction(function () use ($data) {
            $product = SavingsProduct::findOrFail($data['savings_product_id']);

            // If a specific account_no was requested and it's already taken, fall back to auto-generate
            if (! empty($data['account_no']) && SavingsAccount::withTrashed()->where('account_no', $data['account_no'])->exists()) {
                $data['account_no'] = $this->generateAccountNo($product);
            } else {
                $data['account_no'] = $data['account_no'] ?? $this->generateAccountNo($product);
            }
            $data['code'] = $data['code'] ?? str_replace('-', '', $data['account_no']);
            $data['initial_deposit'] = ($data['initial_deposit'] !== null && $data['initial_deposit'] !== '') ? (float) $data['initial_deposit'] : 0;
            $data['balance'] = $data['initial_deposit'];
            $data['interest_rate'] = $product->interest_rate ?? 0;

            if ($product->isFixed()) {
                $tenor = (int) ($data['tenor_months'] ?? $product->default_tenor_months ?? 6);
                $openedAt = now();
                $data['tenor_months'] = $tenor;
                $data['maturity_date'] = $openedAt->copy()->addMonths($tenor)->toDateString();
                $data['account_type'] = 'fixed';
                $data['maturity_action'] = $data['maturity_action'] ?? $product->maturity_action ?? 'manual';

                if (in_array($product->interest_payout_type, ['periodic_payout', 'compound'], true)) {
                    $frequency = $product->interest_posting_frequency ?? 'monthly';
                    $calc = app(FixedDepositCalculator::class);
                    $data['next_interest_date'] = $calc->nextInterestDate($openedAt, $frequency)->toDateString();
                }
            }

            $data['selected_charges'] = $this->resolveCharges($data['charges'] ?? [], $product);

            $account = SavingsAccount::create($data);

            if ($data['initial_deposit'] > 0) {
                // Record initial deposit transaction
                $transaction = Transaction::create([
                    'reference' => 'IDP-'.date('Ymd').'-'.mt_rand(10000, 99999),
                    'member_id' => $account->member_id,
                    'type' => 'deposit',
                    'amount' => $data['initial_deposit'],
                    'payment_mode' => 'cash',
                    'deposited_by' => 'System (Initial Deposit)',
                    'transaction_date' => now()->toDateString(),
                    'account_id' => $account->id,
                    'account_type' => SavingsAccount::class,
                    'narration' => 'Initial Deposit',
                    'created_by' => auth()->check() ? auth()->id() : null,
                ]);

                // Post opening GL entry — DR Cash / CR Savings Liability
                $this->savingsJournal->postDeposit($transaction, $account);
            }

            return $account;
        });
    }

    /**
     * Update an existing savings account.
     */
    public function update(SavingsAccount $account, array $data): bool
    {
        if (isset($data['charges'])) {
            $product = $account->savingsProduct ?? SavingsProduct::find($data['savings_product_id'] ?? $account->savings_product_id);
            $data['selected_charges'] = $this->resolveCharges($data['charges'], $product);
        }

        return $account->update($data);
    }

    /**
     * Delete a savings account.
     */
    public function delete(SavingsAccount $account): ?bool
    {
        return $account->delete();
    }

    /**
     * Resolve charge IDs to their full charge objects for storage in selected_charges.
     * The deposit/withdraw controllers iterate selected_charges expecting objects with
     * 'type', 'charge_type', 'amount', and optionally 'name' keys.
     */
    private function resolveCharges(array $chargeIds, ?SavingsProduct $product): array
    {
        if (empty($chargeIds) || ! $product) {
            return [];
        }

        // If already objects (e.g. re-submitted from an existing account), return as-is
        if (! is_numeric($chargeIds[0] ?? null)) {
            return $chargeIds;
        }

        return $product->charges()
            ->whereIn('id', $chargeIds)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->type, // type doubles as name (deposit/withdraw/transfer)
                'type' => $c->type,
                'charge_type' => $c->charge_type,
                'amount' => (float) $c->amount,
                'minimum_amount' => (float) ($c->minimum_amount ?? 0),
                'maximum_amount' => (float) ($c->maximum_amount ?? 0),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Generate a unique account number based on product and sequence.
     */
    private function generateAccountNo(SavingsProduct $product): string
    {
        $prefix = strtoupper(substr($product->name, 0, 3));
        $count = SavingsAccount::withTrashed()->where('savings_product_id', $product->id)->count() + 1;

        $accountNo = $prefix.'-'.str_pad($count, 6, '0', STR_PAD_LEFT);

        // Ensure uniqueness even including trashed records
        while (SavingsAccount::withTrashed()->where('account_no', $accountNo)->exists()) {
            $count++;
            $accountNo = $prefix.'-'.str_pad($count, 6, '0', STR_PAD_LEFT);
        }

        return $accountNo;
    }
}
