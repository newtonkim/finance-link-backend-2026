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
        // Only touch accounts that have an opening deposit but no ledger activity at
        // all — that is exactly the gate-suppressed case. Accounts with any existing
        // transaction are left untouched to avoid double-posting.
        $accounts = SavingsAccount::where('initial_deposit', '>', 0)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('transactions')
                    ->whereColumn('transactions.account_id', 'savings_accounts.id')
                    ->whereNull('transactions.deleted_at');
            })
            ->get();

        $count = 0;

        foreach ($accounts as $account) {
            $gross = (float) $account->initial_deposit;
            $charge = round($gross - (float) $account->balance, 2);
            $when = $account->created_at ?? now();

            DB::connection('tenant')->transaction(function () use ($account, $gross, $charge, $when) {
                Transaction::create([
                    'reference' => $this->reference(),
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
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);

                if ($charge > 0) {
                    Transaction::create([
                        'reference' => $this->reference(),
                        'member_id' => $account->member_id,
                        'type' => 'charge',
                        'amount' => 0,
                        'charge_amount' => $charge,
                        'deposited_by' => 'System (Initial Deposit)',
                        'transaction_date' => $when,
                        'account_id' => $account->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => 'Initial deposit charge: '.number_format($charge, 2),
                        'created_at' => $when,
                        'updated_at' => $when,
                    ]);
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
}
