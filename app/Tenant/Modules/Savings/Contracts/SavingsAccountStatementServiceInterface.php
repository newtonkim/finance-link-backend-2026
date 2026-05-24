<?php

namespace App\Tenant\Modules\Savings\Contracts;

interface SavingsAccountStatementServiceInterface
{
    /**
     * Build a per-savings-account statement.
     *
     * @return array{
     *   account: array, member: array, branch: array,
     *   period: array, balances: array,
     *   transactions: array<int, array>, warnings: array<int, string>
     * }
     */
    public function buildStatement(int $savingsAccountId, ?string $dateFrom = null, ?string $dateTo = null): array;
}
