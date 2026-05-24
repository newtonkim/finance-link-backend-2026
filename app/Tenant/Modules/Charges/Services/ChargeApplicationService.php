<?php

namespace App\Tenant\Modules\Charges\Services;

use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Charges\Contracts\ChargeJournalServiceInterface;
use App\Tenant\Modules\Members\Models\MemberCharge;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\DB;

class ChargeApplicationService implements ChargeApplicationServiceInterface
{
    public function __construct(
        private readonly ChargeCalculatorServiceInterface $calculator,
        private readonly ChargeJournalServiceInterface $journal,
    ) {}

    public function applyForSavingsEvent(
        int $savingsAccountId,
        string $eventType,
        float $transactionAmount,
        ?int $transactionId,
        int $actorId,
    ): ?MemberCharge {
        $resolved = $this->calculator->resolveForSavings($savingsAccountId, $eventType, $transactionAmount);
        if (! $resolved) {
            return null;
        }

        ['charge' => $charge, 'fee' => $fee] = $resolved;

        // Idempotency: same transaction + same charge → return existing record
        if ($transactionId !== null) {
            $existing = MemberCharge::on('tenant')
                ->where('general_charge_id', $charge->id)
                ->where('transaction_id', $transactionId)
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $account = SavingsAccount::on('tenant')->findOrFail($savingsAccountId);

        return DB::connection('tenant')->transaction(function () use ($charge, $fee, $account, $transactionId, $actorId) {
            $reference = 'CHG-' . now()->format('YmdHis') . '-' . \Illuminate\Support\Str::random(8);

            $memberCharge = MemberCharge::on('tenant')->create([
                'member_id'          => $account->member_id,
                'general_charge_id'  => $charge->id,
                'savings_account_id' => $account->id,
                'charge_name'        => $charge->name,
                'amount'             => $fee,
                'status'             => 'applied',
                'applied_at'         => now(),
                'transaction_id'     => $transactionId,
                'narration'          => "Auto-applied: {$charge->name}",
                'created_by'         => $actorId,
            ]);

            $this->journal->post(
                charge: $charge,
                fee: $fee,
                memberId: $account->member_id,
                savingsAccount: $account,
                reference: $reference,
                postedBy: $actorId,
            );

            return $memberCharge;
        });
    }
}
