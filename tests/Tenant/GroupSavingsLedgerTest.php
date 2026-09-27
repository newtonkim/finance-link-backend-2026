<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Models\JournalEntry;
use App\Tenant\Modules\Accounting\Services\GroupSavingsJournalService;
use App\Tenant\Modules\Groups\Models\SavingsGroup;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/guarantor_helpers.php';

/**
 * Group savings in the general ledger: 21104 Group Savings Deposits, credited and
 * debited as group accounts take deposits and pay out withdrawals, once an account
 * has been brought onto the ledger.
 */
beforeEach(function () {
    Bus::fake();

    $this->staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $this->actingAs($this->staff, 'sanctum');
    seedGuarantorSettings();

    $coa = fn (string $code, string $name, string $type, string $balance) => ChartOfAccount::create([
        'gl_code' => $code, 'name' => $name, 'account_type' => $type, 'account_subtype' => strtolower($type),
        'normal_balance' => $balance, 'level' => 4, 'is_control' => false, 'is_postable' => true, 'is_active' => true,
    ]);
    $this->cash = $coa('11101', 'Petty Cash', 'ASSET', 'DR');
    $this->groupGl = $coa('21104', 'Group Savings Deposits', 'LIABILITY', 'CR');
    $this->openingGl = $coa('33900', 'Opening Balance Control', 'EQUITY', 'CR');
    $coa('42300', 'Account Maintenance Fees', 'INCOME', 'CR');

    // A savings product for the group accounts to use.
    memberWithSavings(0);
    $this->productId = SavingsAccount::first()->savings_product_id;

    $this->group = SavingsGroup::factory()->create();
    $this->groupMember = Member::factory()->create();
    DB::table('savings_group_members')->insert([
        'savings_group_id' => $this->group->id, 'member_id' => $this->groupMember->id,
        'balance' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
});

function journal(): GroupSavingsJournalService
{
    return app(GroupSavingsJournalService::class);
}

function groupAccount(float $balance, bool $onLedger): int
{
    return DB::table('group_savings_accounts')->insertGetId([
        'savings_group_id' => test()->group->id, 'savings_product_id' => test()->productId,
        'balance' => $balance, 'gl_opened_at' => $onLedger ? now() : null,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function groupGlLines(int $afterJournalId)
{
    return JournalEntry::where('id', '>', $afterJournalId)->with('lines')->get()->flatMap->lines;
}

function tellerTransaction(int $accountId, string $type, float $amount)
{
    return test()->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/group-account-savings/group-saving-account-deposit-withdrawal', [
            'group_account_id' => (string) $accountId,
            'member_id' => (string) test()->groupMember->id,
            'amount' => $amount,
            'type' => $type,
            'payment_mode_id' => test()->cash->id,
        ]);
}

it('brings an existing account onto the ledger at its balance, once', function () {
    $id = groupAccount(1500, false);
    $before = (int) JournalEntry::max('id');

    expect(journal()->openLedger($id))->toBeTrue()
        ->and(journal()->openLedger($id))->toBeTrue();

    $lines = groupGlLines($before);
    expect($lines->where('account_id', $this->openingGl->id)->sum('debit'))->toEqual(1500)
        ->and($lines->where('account_id', $this->groupGl->id)->sum('credit'))->toEqual(1500)
        ->and(JournalEntry::where('id', '>', $before)->count())->toBe(1);
});

it('does not journal an account that is not on the ledger yet', function () {
    $id = groupAccount(500, false);

    expect(journal()->postDeposit($id, 100, 0, $this->cash->id, null, 'Deposit'))->toBeNull();
});

it('journals a teller deposit into a group account', function () {
    $id = groupAccount(1000, true);
    $before = (int) JournalEntry::max('id');

    tellerTransaction($id, 'deposit', 300)->assertOk();

    expect((float) DB::table('group_savings_accounts')->where('id', $id)->value('balance'))->toBe(1300.0);
    $lines = groupGlLines($before);
    expect($lines->where('account_id', $this->cash->id)->sum('debit'))->toEqual(300)
        ->and($lines->where('account_id', $this->groupGl->id)->sum('credit'))->toEqual(300)
        ->and(DB::table('sub_ledger')->where('entity_type', GroupSavingsJournalService::ENTITY)->where('entity_id', $id)->sum('credit'))->toEqual(300);
});

it('journals a teller withdrawal from a group account', function () {
    $id = groupAccount(1000, true);
    $before = (int) JournalEntry::max('id');

    $this->group->forceFill(['withdrawal_required_approvals' => 0])->save();

    tellerTransaction($id, 'withdrawal', 200)->assertOk();

    expect((float) DB::table('group_savings_accounts')->where('id', $id)->value('balance'))->toBe(800.0);
    $lines = groupGlLines($before);
    expect($lines->where('account_id', $this->groupGl->id)->sum('debit'))->toEqual(200)
        ->and($lines->where('account_id', $this->cash->id)->sum('credit'))->toEqual(200);
});

it('opens a new group account on the ledger with its initial deposit as cash', function () {
    $before = (int) JournalEntry::max('id');

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/group-account-savings/create-group-saving-account', [
            'group_id' => (string) $this->group->id,
            'product_id' => (string) $this->productId,
            'branch_id' => 1,
            'initial_balance' => 250,
            'new_account' => '1',
            'payment_mode_id' => $this->cash->id,
        ])->assertOk();

    $account = DB::table('group_savings_accounts')->where('savings_group_id', $this->group->id)->first();
    expect($account->gl_opened_at)->not->toBeNull()
        ->and(DB::table('transactions')->where('group_savings_account_id', $account->id)->value('type'))->toBe('deposit');

    $lines = groupGlLines($before);
    expect($lines->where('account_id', $this->cash->id)->sum('debit'))->toEqual(250)
        ->and($lines->where('account_id', $this->groupGl->id)->sum('credit'))->toEqual(250);
});

it('opens an existing group account against opening balance control', function () {
    $before = (int) JournalEntry::max('id');

    $this->withHeaders(['Host' => 'test.mfukopro.test'])
        ->postJson('/api/v1/tenant/group-account-savings/create-group-saving-account', [
            'group_id' => (string) $this->group->id,
            'product_id' => (string) $this->productId,
            'branch_id' => 1,
            'opening_balance' => 700,
            'new_account' => '0',
        ])->assertOk();

    $lines = groupGlLines($before);
    expect($lines->where('account_id', $this->openingGl->id)->sum('debit'))->toEqual(700)
        ->and($lines->where('account_id', $this->groupGl->id)->sum('credit'))->toEqual(700)
        ->and($lines->where('account_id', $this->cash->id)->sum('debit'))->toEqual(0);
});

it('brings every group account onto the ledger from the command', function () {
    groupAccount(100, false);
    groupAccount(200, false);

    $result = journal()->openAllLedgers();

    expect($result['opened'])->toBe(2)
        ->and($result['failed'])->toBe([])
        ->and(DB::table('group_savings_accounts')->whereNull('gl_opened_at')->count())->toBe(0);
});
