<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Entities\Tenant;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Console\Command;

class FixInitialDeposits extends Command
{
    protected $signature = 'fix:initial-deposits';

    protected $description = 'Retroactively creates missing initial deposit transactions for all old accounts.';

    public function handle()
    {
        $tenants = class_exists(Tenant::class) ? Tenant::all() : [];

        foreach ($tenants as $tenant) {
            if (function_exists('tenancy')) {
                tenancy()->initialize($tenant);
            }

            $accounts = SavingsAccount::where('initial_deposit', '>', 0)->get();
            $count = 0;

            foreach ($accounts as $account) {
                $exists = Transaction::where('account_id', $account->id)
                    ->where('type', 'deposit')
                    ->where('narration', 'Initial Deposit')
                    ->exists();

                if (! $exists) {
                    Transaction::create([
                        'reference' => 'IDP-'.date('Ymd').'-'.mt_rand(10000, 99999),
                        'member_id' => $account->member_id,
                        'type' => 'deposit',
                        'amount' => $account->initial_deposit,
                        'payment_mode' => 'cash',
                        'deposited_by' => 'System (Initial Deposit)',
                        'transaction_date' => $account->created_at->toDateString(),
                        'account_id' => $account->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => 'Initial Deposit',
                        'created_by' => null,
                        'created_at' => $account->created_at,
                        'updated_at' => $account->updated_at,
                    ]);
                    $count++;
                }
            }

            $this->info("Fixed {$count} accounts for tenant {$tenant->id}");

            if (function_exists('tenancy')) {
                tenancy()->end();
            }
        }

        $this->info('Done fixing initial deposits.');
    }
}
