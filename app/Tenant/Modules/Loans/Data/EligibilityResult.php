<?php

namespace App\Tenant\Modules\Loans\Data;

class EligibilityResult
{
    public function __construct(
        public readonly bool $eligible,
        public readonly array $passed,
        public readonly array $failed,
        public readonly array $warnings,
        public readonly float $maxEligibleAmount,
    ) {}

    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'passed' => $this->passed,
            'failed' => $this->failed,
            'warnings' => $this->warnings,
            'max_eligible_amount' => $this->maxEligibleAmount,
        ];
    }
}
