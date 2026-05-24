<?php

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

Config::set('database.connections.tenant.database', 'sacco_mzalendo_sacco');
DB::purge('tenant');
// Set default connection so Eloquent models can resolve it cleanly
DB::setDefaultConnection('tenant');

$account = SavingsAccount::where('member_id', 5)->where('initial_deposit', '>', 0)->first();

if ($account) {
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
        echo "Successfully inserted the missing initial deposit transaction for member 5.\n";
    } else {
        echo "The initial deposit transaction already exists for member 5.\n";
    }
} else {
    echo "Could not find a savings account with an > 0 initial deposit for member 5.\n";
}
