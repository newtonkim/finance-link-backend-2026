<?php

namespace Tests\Feature\Tenant\Shares;

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Settings\Models\OnboardingSettings;
use App\Tenant\Modules\Shares\Contracts\ShareAccountingServiceInterface;
use App\Tenant\Modules\Shares\Models\Share;
use Illuminate\Support\Facades\Hash;
use Tests\TenantTestCase;

class SharePurchaseAccountingTest extends TenantTestCase
{
    protected Staff $staff;

    protected Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->staff = Staff::create([
            'name' => 'Accounting Tester',
            'email' => 'acct@test.com',
            'password' => Hash::make('password'),
            'role' => 'Admin',
            'is_tenant_admin' => true,
        ]);

        $this->member = Member::create([
            'name' => 'Test Member',
            'member_number' => 'MBR-SHR-001',
            'code' => 'MBR-SHR-001',
            'password' => Hash::make('password'),
            'status' => 'active',
            'phone' => '0700000001',
        ]);

        // Seed the two GL accounts the service depends on
        ChartOfAccount::create([
            'gl_code' => '11101', 'name' => 'Petty Cash', 'account_type' => 'ASSET',
            'account_subtype' => 'Cash', 'normal_balance' => 'DR',
            'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);
        ChartOfAccount::create([
            'gl_code' => '31100', 'name' => 'Ordinary Share Capital', 'account_type' => 'EQUITY',
            'account_subtype' => 'Share Capital', 'normal_balance' => 'CR',
            'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);

        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '11101')->first();
        OnboardingSettings::current()->update(['share_payment_account_id' => $cashGl->id]);
    }

    public function test_share_purchase_posts_balanced_journal_entry(): void
    {
        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 10,
            'share_value' => 500.00,
            'total_value' => 5000.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingServiceInterface::class);
        $service->postSharePurchaseEntry($share, $this->staff->id);

        $je = JournalEntry::on('tenant')
            ->where('reference_type', 'share')
            ->where('reference', "SHR-{$share->id}")
            ->first();

        $this->assertNotNull($je, 'Expected a journal entry for share purchase');
        $this->assertEquals('posted', $je->status);

        $lines = $je->lines()->get();
        $this->assertCount(2, $lines);

        $cashGl = ChartOfAccount::on('tenant')->where('gl_code', '11101')->first();
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '31100')->first();

        $drLine = $lines->firstWhere('account_id', $cashGl->id);
        $crLine = $lines->firstWhere('account_id', $shareCapitalGl->id);

        $this->assertNotNull($drLine, 'Expected DR line on Cash (11101)');
        $this->assertNotNull($crLine, 'Expected CR line on Share Capital (31100)');
        $this->assertEquals(5000.00, (float) $drLine->debit);
        $this->assertEquals(5000.00, (float) $crLine->credit);

        // Trial balance check: DR total = CR total
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
    }

    public function test_share_purchase_uses_configured_payment_account(): void
    {
        // Create a bank account — different from the default 11101 (Petty Cash)
        $bankGl = ChartOfAccount::create([
            'gl_code' => '1121', 'name' => 'Bank Account', 'account_type' => 'ASSET',
            'account_subtype' => 'Bank', 'normal_balance' => 'DR',
            'level' => 3, 'is_control' => false, 'is_postable' => true,
        ]);

        // Override OnboardingSettings to use the bank account
        OnboardingSettings::current()->update(['share_payment_account_id' => $bankGl->id]);

        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 8,
            'share_value' => 500.00,
            'total_value' => 4000.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingServiceInterface::class);
        $service->postSharePurchaseEntry($share, $this->staff->id);

        $je = JournalEntry::on('tenant')
            ->where('reference_type', 'share')
            ->where('reference', "SHR-{$share->id}")
            ->first();

        $this->assertNotNull($je, 'Expected a journal entry for share purchase');

        $lines = $je->lines()->get();
        $shareCapitalGl = ChartOfAccount::on('tenant')->where('gl_code', '31100')->first();

        $drLine = $lines->firstWhere('account_id', $bankGl->id);
        $crLine = $lines->firstWhere('account_id', $shareCapitalGl->id);

        $this->assertNotNull($drLine, 'Expected DR line on Bank Account (1121), not Petty Cash (11101)');
        $this->assertNotNull($crLine, 'Expected CR line on Share Capital (31100)');
        $this->assertEquals(4000.00, (float) $drLine->debit);
        $this->assertEquals(4000.00, (float) $crLine->credit);
        $this->assertEquals($lines->sum('debit'), $lines->sum('credit'));
    }

    public function test_share_purchase_skips_entry_when_gl_not_configured(): void
    {
        ChartOfAccount::on('tenant')->where('gl_code', '11101')->delete();
        ChartOfAccount::on('tenant')->where('gl_code', '31100')->delete();

        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 5,
            'share_value' => 500.00,
            'total_value' => 2500.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingServiceInterface::class);
        $service->postSharePurchaseEntry($share, $this->staff->id);

        $this->assertEquals(
            0,
            JournalEntry::on('tenant')
                ->where('reference_type', 'share')
                ->count(),
            'Expected no journal entries when GL accounts are not seeded'
        );
    }

    public function test_share_purchase_skips_when_payment_account_id_is_null(): void
    {
        OnboardingSettings::current()->update(['share_payment_account_id' => null]);

        $share = Share::create([
            'member_id' => $this->member->id,
            'share_no' => 3,
            'share_value' => 500.00,
            'total_value' => 1500.00,
            'purchased_at' => now()->toDateString(),
        ]);

        $service = app(ShareAccountingServiceInterface::class);
        $service->postSharePurchaseEntry($share, $this->staff->id);

        $this->assertEquals(
            0,
            JournalEntry::on('tenant')
                ->where('reference_type', 'share')
                ->count(),
            'Expected no journal entries when share_payment_account_id is null'
        );
    }
}
