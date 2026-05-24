<?php

namespace Tests\Feature\Tenant;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use App\Tenant\Modules\Accounting\Services\ChartOfAccountService;
use Tests\TenantTestCase;

class CannotDeleteAccountReferencedByChargeTest extends TenantTestCase
{
    public function test_blocks_deletion_when_a_general_charge_references_the_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '42999',
            'name' => 'Test Inline Income',
            'account_type' => 'INCOME',
            'normal_balance' => 'CR',
            'is_postable' => true,
            'is_active' => true,
            'is_control' => false,
            'level' => 3,
        ]);

        GeneralCharge::create([
            'name' => 'Test Charge',
            'is_revenue' => true,
            'application' => 'on_loan_application',
            'amount' => 1000,
            'credit_account_id' => $account->id,
            'is_reversible' => true,
        ]);

        $service = app(ChartOfAccountService::class);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot delete account referenced by a general charge.');

        $service->deleteAccount($account->id);
    }

    public function test_still_allows_deletion_when_no_general_charge_references_the_account(): void
    {
        $account = ChartOfAccount::create([
            'gl_code' => '42998',
            'name' => 'Test Orphan Income',
            'account_type' => 'INCOME',
            'normal_balance' => 'CR',
            'is_postable' => true,
            'is_active' => true,
            'is_control' => false,
            'level' => 3,
        ]);

        $service = app(ChartOfAccountService::class);

        $result = $service->deleteAccount($account->id);
        $this->assertTrue($result);
    }
}
