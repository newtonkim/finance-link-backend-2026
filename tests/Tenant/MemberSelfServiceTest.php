<?php

use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Transactions\Models\MemberTransactionRequest;
use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\Hash;

function memberSelfServiceUrl(string $path): string
{
    return 'http://test.mfukopro.test/api/v1/tenant/member/'.$path;
}

it('MemberSelfService allows an active member to login and view profile', function () {
    $member = Member::factory()->create([
        'email' => 'member@example.test',
        'password' => Hash::make('password123'),
        'status' => 'active',
    ]);

    $response = $this->postJson(memberSelfServiceUrl('auth/login'), [
        'email' => 'member@example.test',
        'password' => 'password123',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.member.id', $member->id);

    $this->actingAs($member, 'sanctum')
        ->getJson(memberSelfServiceUrl('me'))
        ->assertOk()
        ->assertJsonPath('data.id', $member->id);
});

it('MemberSelfService rejects inactive member login', function () {
    Member::factory()->create([
        'email' => 'pending-member@example.test',
        'password' => Hash::make('password123'),
        'status' => 'pending',
    ]);

    $this->postJson(memberSelfServiceUrl('auth/login'), [
        'email' => 'pending-member@example.test',
        'password' => 'password123',
    ])->assertStatus(403);
});

it('MemberSelfService only exposes the authenticated members accounts', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $other = Member::factory()->create(['status' => 'active']);

    $ownAccount = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 1000]);
    $otherAccount = SavingsAccount::factory()->create(['member_id' => $other->id, 'balance' => 2000]);

    $this->actingAs($member, 'sanctum')
        ->getJson(memberSelfServiceUrl('accounts'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownAccount->id);

    $this->actingAs($member, 'sanctum')
        ->getJson(memberSelfServiceUrl("accounts/{$otherAccount->id}"))
        ->assertNotFound();
});

it('MemberSelfService rejects staff tokens on member routes', function () {
    $staff = Staff::factory()->create(['is_tenant_admin' => true]);

    $this->actingAs($staff, 'sanctum')
        ->getJson(memberSelfServiceUrl('accounts'))
        ->assertForbidden();
});

it('MemberSelfService rejects member tokens on staff tenant routes', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $this->actingAs($member, 'sanctum')
        ->getJson('http://test.mfukopro.test/api/v1/tenant/savings-accounts')
        ->assertForbidden();
});

it('MemberSelfService rejects inactive staff tokens on staff tenant routes', function () {
    $staff = Staff::factory()->create([
        'status' => 'inactive',
        'is_tenant_admin' => true,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->getJson('http://test.mfukopro.test/api/v1/tenant/savings-accounts')
        ->assertForbidden()
        ->assertJsonPath('message', 'Staff account is not active.');
});

it('MemberSelfService returns account and member statements for owned accounts', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 500]);

    Transaction::create([
        'reference' => 'TXN-MEMBER-STATEMENT-1',
        'receipt_number' => 'RCPT-MEMBER-STATEMENT-1',
        'member_id' => $member->id,
        'type' => 'deposit',
        'amount' => 500,
        'charge_amount' => 0,
        'payment_mode' => 'cash',
        'transaction_date' => now()->toDateString(),
        'account_id' => $account->id,
        'account_type' => SavingsAccount::class,
        'narration' => 'Opening',
    ]);

    $this->actingAs($member, 'sanctum')
        ->getJson(memberSelfServiceUrl("accounts/{$account->id}/statement"))
        ->assertOk()
        ->assertJsonPath('account.id', $account->id);

    $this->actingAs($member, 'sanctum')
        ->getJson(memberSelfServiceUrl('statement'))
        ->assertOk()
        ->assertJsonPath('member.id', $member->id)
        ->assertJsonPath('transactions.data.0.account_id', $account->id);
});

it('MemberSelfService creates deposit requests without changing balance', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 1000]);

    $this->actingAs($member, 'sanctum')
        ->postJson(memberSelfServiceUrl('deposit-requests'), [
            'savings_account_id' => $account->id,
            'requested_date' => now()->toDateString(),
            'amount' => 250,
            'payment_mode' => 'cash',
            'narration' => 'Member cash deposit',
        ])
        ->assertCreated()
        ->assertJsonPath('data.status', MemberTransactionRequest::STATUS_PENDING);

    expect((float) $account->fresh()->balance)->toBe(1000.00);

    $this->assertDatabaseHas('member_transaction_requests', [
        'member_id' => $member->id,
        'savings_account_id' => $account->id,
        'type' => MemberTransactionRequest::TYPE_DEPOSIT,
        'status' => MemberTransactionRequest::STATUS_PENDING,
    ], 'tenant');
});

it('MemberSelfService blocks requests against another members account', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $other = Member::factory()->create(['status' => 'active']);
    $otherAccount = SavingsAccount::factory()->create(['member_id' => $other->id, 'balance' => 1000]);

    $this->actingAs($member, 'sanctum')
        ->postJson(memberSelfServiceUrl('deposit-requests'), [
            'savings_account_id' => $otherAccount->id,
            'requested_date' => now()->toDateString(),
            'amount' => 250,
            'payment_mode' => 'cash',
        ])
        ->assertNotFound();
});

it('MemberSelfService validates withdrawal requests against minimum balance', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $product = SavingsProduct::factory()->create(['minimum_balance' => 100]);
    $account = SavingsAccount::factory()->create([
        'member_id' => $member->id,
        'savings_product_id' => $product->id,
        'balance' => 150,
        'consider_min_balance' => true,
    ]);

    $this->actingAs($member, 'sanctum')
        ->postJson(memberSelfServiceUrl('withdrawal-requests'), [
            'savings_account_id' => $account->id,
            'requested_date' => now()->toDateString(),
            'amount' => 100,
            'payment_mode' => 'cash',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('amount');
});

it('MemberSelfService lets staff approve a deposit request through normal posting', function () {
    $staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 1000]);

    $transactionRequest = MemberTransactionRequest::create([
        'member_id' => $member->id,
        'savings_account_id' => $account->id,
        'type' => MemberTransactionRequest::TYPE_DEPOSIT,
        'amount' => 500,
        'payment_mode' => 'cash',
        'requested_date' => now()->toDateString(),
        'status' => MemberTransactionRequest::STATUS_PENDING,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->postJson("http://test.mfukopro.test/api/v1/tenant/member-transaction-requests/{$transactionRequest->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', MemberTransactionRequest::STATUS_APPROVED);

    expect((float) $account->fresh()->balance)->toBe(1500.00);

    $this->assertDatabaseHas('transactions', [
        'member_id' => $member->id,
        'account_id' => $account->id,
        'type' => 'deposit',
        'amount' => '500.00',
        'created_by' => $staff->id,
    ], 'tenant');
});

it('MemberSelfService lets staff reject a request without changing balance', function () {
    $staff = Staff::factory()->create(['is_tenant_admin' => true]);
    $member = Member::factory()->create(['status' => 'active']);
    $account = SavingsAccount::factory()->create(['member_id' => $member->id, 'balance' => 1000]);

    $transactionRequest = MemberTransactionRequest::create([
        'member_id' => $member->id,
        'savings_account_id' => $account->id,
        'type' => MemberTransactionRequest::TYPE_WITHDRAWAL,
        'amount' => 300,
        'payment_mode' => 'cash',
        'requested_date' => now()->toDateString(),
        'status' => MemberTransactionRequest::STATUS_PENDING,
    ]);

    $this->actingAs($staff, 'sanctum')
        ->postJson("http://test.mfukopro.test/api/v1/tenant/member-transaction-requests/{$transactionRequest->id}/reject", [
            'review_reason' => 'Missing supporting details.',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', MemberTransactionRequest::STATUS_REJECTED);

    expect((float) $account->fresh()->balance)->toBe(1000.00);
});
