<?php

namespace App\Tenant\Modules\Charges\Services;

use App\Tenant\Modules\Charges\Contracts\ChargeCalculatorServiceInterface;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;

class ChargeCalculatorService implements ChargeCalculatorServiceInterface
{
    public function resolveForSavings(int $savingsAccountId, string $eventType, float $transactionAmount): ?array
    {
        $account = SavingsAccount::on('tenant')->find($savingsAccountId);
        if (! $account) {
            return null;
        }

        $productCharge = SavingsProductCharge::on('tenant')
            ->with('generalCharge')
            ->where('savings_product_id', $account->savings_product_id)
            ->where('type', $eventType)
            ->whereNotNull('general_charge_id')
            ->whereHas('generalCharge', fn ($q) => $q->where('is_active', true))
            ->first();

        $charge = $productCharge?->generalCharge;
        if (! $charge) {
            return null;
        }

        $fee = $charge->charge_type === 'percentage'
            ? round($transactionAmount * ((float) $charge->amount / 100), 2)
            : (float) $charge->amount;

        if ($fee <= 0) {
            return null;
        }

        return ['charge' => $charge, 'fee' => $fee];
    }

    public function resolveForRegistration(int $savingsProductId): array
    {
        return SavingsProductCharge::on('tenant')
            ->with('generalCharge')
            ->where('savings_product_id', $savingsProductId)
            ->where('type', 'registration')
            ->whereNotNull('general_charge_id')
            ->whereHas('generalCharge', fn ($q) => $q->where('is_active', true))
            ->get()
            ->map(function (SavingsProductCharge $pc) {
                $charge = $pc->generalCharge;

                return [
                    'general_charge_id' => (int) $charge->id,
                    'name'              => (string) $charge->name,
                    'amount'            => (float) $charge->amount,
                    'credit_account_id' => $charge->credit_account_id !== null ? (int) $charge->credit_account_id : null,
                    'is_reversible'     => (bool) $charge->is_reversible,
                ];
            })
            ->values()
            ->all();
    }
}
