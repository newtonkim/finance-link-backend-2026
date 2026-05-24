<?php

namespace App\Tenant\Modules\Charges\Contracts;

interface ChargeCalculatorServiceInterface
{
    /**
     * Returns ['charge' => GeneralCharge, 'fee' => float] or null if no charge applies.
     */
    public function resolveForSavings(int $savingsAccountId, string $eventType, float $transactionAmount): ?array;

    /**
     * Resolve all active product-scoped registration charges for a savings product.
     *
     * Unlike resolveForSavings which returns a single match per event, registration
     * can stack multiple charges per product (e.g. Membership Card + Account Opening Fee).
     *
     * @return array<int, array{general_charge_id: int, name: string, amount: float, credit_account_id: ?int, is_reversible: bool}>
     */
    public function resolveForRegistration(int $savingsProductId): array;
}
