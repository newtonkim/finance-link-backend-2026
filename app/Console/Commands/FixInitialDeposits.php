<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Infrastructure\Tenancy\DatabaseSwitcher;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FixInitialDeposits extends Command
{
    protected $signature = 'fix:initial-deposits
        {--tenant= : Tenant subdomain to process (default: all tenants)}
        {--rollback : Remove previously backfilled opening transactions instead of creating them}';

    protected $description = 'Backfill the missing opening deposit (and its charge) for savings accounts that have no transactions, so the member ledger reconciles to the account balance.';

    /** Reference prefix that tags backfilled rows so the operation stays reversible. */
    private const REF_PREFIX = 'BACKFILL-OPEN-';

    public function handle(DatabaseSwitcher $switcher): int
    {
        $tenants = $this->resolveTenants();
        if ($tenants->isEmpty()) {
            $this->error('No matching tenant found.');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $switcher->switch($tenant);

            $this->option('rollback')
                ? $this->rollbackTenant($tenant)
                : $this->backfillTenant($tenant);

            $switcher->purge();
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    private function resolveTenants(): Collection
    {
        $subdomain = $this->option('tenant');

        return $subdomain
            ? Tenant::where('subdomain', $subdomain)->get()
            : Tenant::all();
    }

    private function backfillTenant(Tenant $tenant): void
    {
        // Backfill the opening deposit for any account that has an initial deposit
        // but no opening-deposit transaction recorded — the gate-suppressed case.
        // Keyed on the opening marker (not "zero transactions") so accounts that
        // have since had other activity still get their missing opening entry, and
        // re-runs never duplicate it.
        $accounts = SavingsAccount::where('initial_deposit', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('transactions')
                    ->whereColumn('transactions.account_id', 'savings_accounts.id')
                    ->whereNull('transactions.deleted_at')
                    ->where('transactions.type', 'deposit')
                    ->where(function ($marker) {
                        $marker->where('transactions.deposited_by', 'System (Initial Deposit)')
                            ->orWhere('transactions.narration', 'like', 'Initial deposit%')
                            ->orWhere('transactions.reference', 'like', self::REF_PREFIX.'%');
                    });
            })
            ->get();

        $count = 0;

        foreach ($accounts as $account) {
            $gross = (float) $account->initial_deposit;
            // Derive the opening charge from product config (cannot use the current
            // balance once the account has had other movements).
            $charge = $this->openingCharge($account->savings_product_id, $gross);
            $when = $account->created_at ?? now();

            // Shared receipt number so the deposit and its charge group together on
            // the receipt and in the ledger (matches how live deposits are linked).
            $receiptNumber = $this->reference();

            DB::connection('tenant')->transaction(function () use ($account, $gross, $charge, $when, $receiptNumber) {
                $deposit = Transaction::create([
                    'reference' => $this->reference(),
                    'receipt_number' => $receiptNumber,
                    'member_id' => $account->member_id,
                    'type' => 'deposit',
                    'amount' => $gross,
                    'charge_amount' => 0,
                    'deposited_amount_before_charge' => $gross,
                    'payment_mode' => 'cash',
                    'deposited_by' => 'System (Initial Deposit)',
                    'transaction_date' => $when,
                    'account_id' => $account->id,
                    'account_type' => SavingsAccount::class,
                    'narration' => 'Initial deposit',
                ]);
                // created_at is not mass-assignable, so Eloquent stamps it with the
                // current time. Force it to the account creation time so the opening
                // sorts before any later same-day deposit in the ledger.
                $this->stampCreatedAt($deposit, $when);

                if ($charge > 0) {
                    $chargeTxn = Transaction::create([
                        'reference' => $this->reference(),
                        'receipt_number' => $receiptNumber,
                        'grouped_with' => $receiptNumber,
                        'member_id' => $account->member_id,
                        'type' => 'charge',
                        'amount' => 0,
                        'charge_amount' => $charge,
                        'deposited_by' => 'System (Initial Deposit)',
                        'transaction_date' => $when,
                        'account_id' => $account->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => 'Initial deposit charge: '.number_format($charge, 2),
                    ]);
                    $this->stampCreatedAt($chargeTxn, $when);
                }
            });

            $count++;
        }

        $this->info("[{$tenant->subdomain}] Backfilled opening transactions for {$count} account(s).");
    }

    private function rollbackTenant(Tenant $tenant): void
    {
        $removed = Transaction::where('reference', 'like', self::REF_PREFIX.'%')->forceDelete();
        $this->info("[{$tenant->subdomain}] Removed {$removed} backfilled transaction(s).");
    }

    private function reference(): string
    {
        return self::REF_PREFIX.now()->format('YmdHis').'-'.mt_rand(100000, 999999);
    }

    /** Force created_at/updated_at (not mass-assignable) to the given time. */
    private function stampCreatedAt(Transaction $transaction, $when): void
    {
        Transaction::where('id', $transaction->id)->update([
            'created_at' => $when,
            'updated_at' => $when,
        ]);
    }

    /** Opening deposit charge for the product, mirroring ProductChargesservice. */
    private function openingCharge(?int $productId, float $amount): float
    {
        if (! $productId) {
            return 0.0;
        }

        $charge = DB::connection('tenant')->table('savings_product_charges')
            ->where('savings_product_id', $productId)
            ->where('type', 'deposit')
            ->whereRaw('? BETWEEN minimum_amount AND maximum_amount', [$amount])
            ->first(['amount', 'charge_type']);

        if (! $charge) {
            return 0.0;
        }

        return $charge->charge_type === 'percentage'
            ? round($amount * (float) $charge->amount / 100, 2)
            : (float) $charge->amount;
    }
}
