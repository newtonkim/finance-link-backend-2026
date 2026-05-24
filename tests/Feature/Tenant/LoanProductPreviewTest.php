<?php

namespace Tests\Feature\Tenant;

use App\Tenant\Modules\Loans\Services\LoanProductPreviewService;
use Tests\TenantTestCase;

class LoanProductPreviewTest extends TenantTestCase
{
    public function test_flat_preview_returns_expected_shape(): void
    {
        $preview = app(LoanProductPreviewService::class)->preview([
            'preview_amount' => 120000,
            'preview_term' => 12,
            'interest_rate' => 2,
            'interest_method' => 'flat',
            'interest_period' => 'per_month',
            'repayment_cycle' => 'monthly',
        ]);

        $this->assertArrayHasKey('installment_amount', $preview);
        $this->assertArrayHasKey('total_interest', $preview);
        $this->assertArrayHasKey('schedule_preview', $preview);
        $this->assertNotEmpty($preview['schedule_preview']);
    }

    public function test_reducing_equal_installment_preview_returns_schedule(): void
    {
        $preview = app(LoanProductPreviewService::class)->preview([
            'preview_amount' => 120000,
            'preview_term' => 12,
            'interest_rate' => 24,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_installment',
            'interest_period' => 'per_year',
            'repayment_cycle' => 'monthly',
        ]);

        $this->assertGreaterThan(0, $preview['installment_amount']);
        $this->assertCount(12, $preview['schedule_preview']);
    }

    public function test_reducing_equal_principal_preview_returns_schedule(): void
    {
        $preview = app(LoanProductPreviewService::class)->preview([
            'preview_amount' => 120000,
            'preview_term' => 12,
            'interest_rate' => 24,
            'interest_method' => 'reducing_balance',
            'repayment_structure' => 'equal_principal',
            'interest_period' => 'per_year',
            'repayment_cycle' => 'monthly',
        ]);

        $this->assertGreaterThan(0, $preview['installment_amount']);
        $this->assertCount(12, $preview['schedule_preview']);
    }
}
