<?php

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill the opening deposit (and its charge) for savings accounts that hold
 * an initial deposit but never got a matching ledger entry.
 *
 * Accounts opened before the "always record the opening deposit" fix only wrote
 * the opening transactions when a display setting was enabled: the money moved
 * (balance updated) but no transaction row existed, so the member ledger showed
 * "No transactions" while the balance was non-zero. The forward path is fixed,
 * but existing orphaned accounts stay broken until repaired — this migration
 * makes every tenant self-heal on deploy instead of relying on a manual command.
 *
 * Mirrors App\Console\Commands\FixInitialDeposits: idempotent (guarded on the
 * opening marker, so accounts already carrying an opening deposit are skipped
 * and re-runs never duplicate) and reversible via the BACKFILL-OPEN- prefix.
 */
return new class extends Migration
{
    /** Reference prefix that tags backfilled rows so the operation stays reversible. */
    private const REF_PREFIX = 'BACKFILL-OPEN-';

    public function up(): void
    {
        $connection = DB::connection('tenant');

        $accounts = $connection->table('savings_accounts')
            ->where('initial_deposit', '>', 0)
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
            ->get(['id', 'member_id', 'savings_product_id', 'initial_deposit', 'created_at']);

        foreach ($accounts as $account) {
            $gross = (float) $account->initial_deposit;
            // Derive the opening charge from product config — the current balance
            // cannot be trusted once the account has had other movements.
            $charge = $this->openingCharge($connection, $account->savings_product_id, $gross);
            $when = $account->created_at ?? now();

            // Shared receipt number so the deposit and its charge group together on
            // the receipt and in the ledger (matches how live deposits are linked).
            $receiptNumber = $this->reference();

            $connection->transaction(function () use ($connection, $account, $gross, $charge, $when, $receiptNumber) {
                $connection->table('transactions')->insert([
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
                    'created_at' => $when,
                    'updated_at' => $when,
                ]);

                if ($charge > 0) {
                    $connection->table('transactions')->insert([
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
                        'created_at' => $when,
                        'updated_at' => $when,
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        DB::connection('tenant')
            ->table('transactions')
            ->where('reference', 'like', self::REF_PREFIX.'%')
            ->delete();
    }

    private function reference(): string
    {
        return self::REF_PREFIX.now()->format('YmdHis').'-'.mt_rand(100000, 999999);
    }

    /** Opening deposit charge for the product, mirroring ProductChargesservice. */
    private function openingCharge($connection, ?int $productId, float $amount): float
    {
        if (! $productId) {
            return 0.0;
        }

        $charge = $connection->table('savings_product_charges')
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
};
