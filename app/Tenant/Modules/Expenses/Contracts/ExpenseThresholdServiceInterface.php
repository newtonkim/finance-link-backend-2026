<?php

namespace App\Tenant\Modules\Expenses\Contracts;

interface ExpenseThresholdServiceInterface
{
    /**
     * Retrieve all approval thresholds.
     */
    public function getThresholds(): \Illuminate\Support\Collection;

    /**
     * Update the approval thresholds matrix.
     *
     * @param array $thresholds
     * @return void
     */
    public function updateThresholds(array $thresholds): void;
}
