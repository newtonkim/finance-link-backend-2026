<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Support\BranchContext;
use App\Tenant\Http\Resources\SavingsAccountResource;
use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Services\SavingsJournalService;
use App\Tenant\Modules\Charges\Contracts\ChargeApplicationServiceInterface;
use App\Tenant\Modules\Members\Services\MemberChargeService;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Services\FixedDepositInterestService;
use App\Tenant\Modules\Savings\Services\SavingsAccountService;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Services\TenantSavingsAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SavingsAccountController extends TenantSavingsAccountService
{
    public function __construct(
        protected SavingsAccountService $service,
        protected SavingsJournalService $savingsJournal,
        private readonly ChargeApplicationServiceInterface $chargeApplication,
    ) {}

    /**
     * List all savings accounts (paginated, searchable).
     */
    public function member_account_create()
    {
        return $this->Response(['data' => self::memberAccountCreate()]);
    }

    public function import_opening_balance()
    {
        return $this->Response([
            'data' => self::importOpeningBalance(),
        ]);
    }

    public function group_account_download_template()
    {
        return $this->Response(['data' => self::groupAccountDownloadTemplate()]);
    }
    public function group_member_download_template()
    {
        return $this->Response(['data' => self::groupMemberDownloadTemplate()]);
    }


    public function import_groups()
    {
        return $this->Response(['data' => self::importGroups()]);
    }
    public function import_group_account_member()
    {
        return $this->Response(['data' => self::importGroupAccountMembers()]);
    }
    public function download_members_account_import_template()
    {
        return $this->Response(['data' => self::downloadMemberAccountImportTemplate()]);
    }

    public function download_members_account_deposit_template()
    {
        return $this->Response(['data' => self::downloadMemberAccountDepositTemplate()]);
    }

    public function import_members_withdrawal_and_deposits()
    {
        return $this->Response(['data' => self::importMembersWithdrawalAnddeposits()]);
    }

    public function member_account_withdrawal()
    {
        return $this->Response(['data' => self::memberAccountWithdrawal()]);
    }

    public function get_member_account_details()
    {
        return $this->Response(['data' => self::memberAccountDetails()]);
    }

    public function member_account_delete()
    {
        return $this->Response(['data' => self::memberAccountDelete()]);
    }

    public function get_member_account_list()
    {
        return $this->Response(['data' => self::memberAccountList()]);
    }

    public function complete_member_account_transfer()
    {
        return $this->Response(['data' => self::transferAmountApprove()]);
    }

    public function member_account_reversal()
    {
        return $this->Response(['data' => self::memberAccountReversal()]);
    }

    public function print_member_account()
    {
        return $this->Response(['data' => self::printMemberAccount()]);
    }

    // group account
    public function get_group_account_list()
    {
        return $this->Response(['data' => self::groupAccountList()]);
    }

    public function group_account_create()
    {
        return $this->Response(['data' => self::groupAccountCreate()]);
    }

    public function edit_member_account_details()
    {
        return $this->Response(['data' => self::editMemberAccountDetails()]);
    }

    public function get_group_account_details()
    {
        return $this->Response(['data' => self::groupAccountDetails()]);
    }

    public function add_member_group_drop_down_list()
    {
        return $this->Response(['data' => self::addMemberGroupDropDownList()]);
    }

    public function print_group_account_profile()
    {
        return $this->Response(['data' => self::printGroupAccountTransactions()]);
    }

    public function group_account_transactions_download_pdf()
    {
        return self::groupAccountTransactionsDownload();
        // return $this->Response(['data' => self::groupAccountTransactionsDownload()]);
    }

    public function group_account_transactions()
    {
        return $this->Response(['data' => self::groupAccountTransactions()]);
    }

    public function group_saving_account_deposit_withdrawal()
    {
        return $this->Response(['data' => self::groupSavingAccountDepositWithdrawal()]);
    }

    public function create_group_saving_account()
    {
        return $this->Response(['data' => self::createGroupSavingAccount()]);
    }

    public function collect_group_saving_account_list()
    {
        return $this->Response(['data' => self::collectGroupSavingAccountList()]);
    }

    public function get_group_member_with_running_loans()
    {
        return $this->Response(['data' => self::getGroupMemberWithRunningLoans()]);
    }

    public function download_group_members_list()
    {
        return self::downloadGroupMembersList();
    }

    public function group_account_profile_completeness()
    {
        return $this->Response(['data' => self::groupAccountProfileCompleteness()]);
    }

    
    public function groups_drop_down_list()
    {
        return $this->Response(['data' => self::groupsDropDownList()]);
    }

    public function savings_accounts_drop_down_list()
    {
        return $this->Response(['data' => self::savingsAccountsDropDownList()]);
    }

    public function group_account_delete()
    {
        return $this->Response(['data' => self::groupAccountDelete()]);
    }

    public function get_transfer_list()
    {
        return $this->Response(['data' => self::transferList()]);
    }

    public function get_transfer_details()
    {
        return $this->Response(['data' => self::transferDetails()]);
    }

    public function transfer_create()
    {
        return $this->Response(['data' => self::transferAmountCreate()]);
    }

    public function get_group_none_members_list()
    {
        // return $this->Response(['data' => self::groupNoneMembers()]);
    }

    public function get_group_none_members_create()
    {
        return $this->Response(['data' => self::groupNoneMembersCreate()]);
    }

    public function import_member_accounts()
    {
        return $this->Response(['data' => self::importMemberAccounts()]);
    }

    public function index(Request $request)
    {
        $accounts = $this->service->list($request->only('search', 'status', 'member_id'), 15);

        return SavingsAccountResource::collection($accounts);
    }

    /**
     * Show a single savings account.
     */
    public function show(SavingsAccount $savingsAccount): JsonResponse
    {
        if ($savingsAccount->isFixed()) {
            app(FixedDepositInterestService::class)
                ->processAccount($savingsAccount, auth()->id() ?? 1);
            $savingsAccount->refresh();
        }

        return response()->json([
            'data' => new SavingsAccountResource($savingsAccount->load(['member', 'savingsProduct'])),
        ]);
    }

    /**
     * Create a new savings account.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'member_id' => ['required', 'exists:tenant.members,id'],
            'savings_product_id' => ['required', 'exists:tenant.savings_products,id'],
            'account_type' => ['required', 'string'],
            'initial_deposit' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:active,dormant,closed'],
            'is_new_account' => ['nullable', 'boolean'],
            'consider_min_balance' => ['nullable', 'boolean'],
            'charges' => ['nullable', 'array'],
            'charges.*' => ['integer'],
            'tenor_months' => ['nullable', 'integer', 'min:1'],
            'maturity_action_override' => ['nullable', 'string', 'in:manual,auto_rollover,convert_to_savings'],
            'payout_savings_account_id' => ['nullable', 'exists:tenant.savings_accounts,id'],
        ]);

        // Minimum balance check on account creation
        $product = SavingsProduct::find($validated['savings_product_id']);
        if ($product && (float) $validated['initial_deposit'] < (float) $product->minimum_balance) {
            $fmt = number_format($product->minimum_balance, 2);

            return response()->json([
                'message' => "Initial deposit must be at least UGX {$fmt} for this product.",
                'errors' => ['initial_deposit' => ["Initial deposit must be at least UGX {$fmt} for the '{$product->name}' product."]],
            ], 422);
        }

        // Map maturity_action_override → maturity_action for the service layer
        if (! empty($validated['maturity_action_override'])) {
            $validated['maturity_action'] = $validated['maturity_action_override'];
        }
        unset($validated['maturity_action_override']);

        try {
            $account = $this->service->create(array_merge($validated, [
                'is_new_account' => $validated['is_new_account'] ?? true,
                'consider_min_balance' => $validated['consider_min_balance'] ?? true,
                'status' => $validated['status'] ?? 'active',
            ]));

            return response()->json([
                'message' => 'Savings account created successfully.',
                'data' => new SavingsAccountResource($account->load(['member', 'savingsProduct'])),
            ], 201);
        } catch (\Exception $e) {
            Log::error('SavingsAccount creation failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to create savings account: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update a savings account.
     */
    public function update(Request $request, SavingsAccount $savingsAccount)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'in:active,dormant,closed'],
        ]);

        try {
            $savingsAccount->update($validated);

            return response()->json([
                'message' => 'Savings account updated successfully.',
                'data' => new SavingsAccountResource($savingsAccount->load(['member', 'savingsProduct'])),
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update savings account.'], 500);
        }
    }

    /**
     * Soft delete a savings account.
     */
    public function destroy(SavingsAccount $savingsAccount)
    {
        try {
            $savingsAccount->delete();

            return response()->json(['message' => 'Savings account deleted successfully.']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to delete savings account.'], 500);
        }
    }

    /**
     * Handle savings deposit.
     */
    public function deposit(Request $request, SavingsAccount $savingsAccount)
    {
        $member = $savingsAccount->member;
        if ($member && $member->status !== 'active') {
            return response()->json([
                'message' => 'Member account is pending approval and cannot deposit.',
                'errors' => ['member' => ['This member must be approved before any transactions can be made.']],
            ], 403);
        }

        $validated = $request->validate([
            'deposit_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'deposited_by' => 'nullable|string|max:255',
            'payment_mode' => 'required|string|max:50',
            'narration' => 'nullable|string',
            'use_for_loan_repayment' => 'nullable|string|in:yes,no',
        ]);

        $receiptNumber = $this->generateReceiptNumber();
        $transactionRef = $this->generateTransactionReference();
        $branchId = BranchContext::actingBranchId();

        DB::connection('tenant')->transaction(function () use ($savingsAccount, $validated, $member, $receiptNumber, $transactionRef, $branchId) {
            $savingsAccount->balance += $validated['amount'];
            $savingsAccount->loadMissing('savingsProduct.charges');

            // Apply deposit charges
            $depositAmount = (float) $validated['amount'];
            $selectedCharges = $this->resolveSelectedCharges($savingsAccount);
            foreach ($selectedCharges as $charge) {
                if (($charge['type'] ?? '') !== 'deposit') {
                    continue;
                }

                // Respect amount range if defined
                $min = (float) ($charge['minimum_amount'] ?? 0);
                $max = (float) ($charge['maximum_amount'] ?? 0);
                if ($max > 0 && ($depositAmount < $min || $depositAmount > $max)) {
                    continue;
                }

                $chargeAmount = ($charge['charge_type'] ?? '') === 'percentage'
                    ? round($depositAmount * ($charge['amount'] ?? 0) / 100, 2)
                    : (float) ($charge['amount'] ?? 0);

                if ($chargeAmount > 0) {
                    $savingsAccount->balance -= $chargeAmount;
                    $chargeRef = 'CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
                    $chargeName = $charge['name'] ?? null;
                    $chargeNarration = 'Deposit Charge'.($chargeName ? ': '.$chargeName : '');

                    $chargeTxn = Transaction::create([
                        'reference' => $chargeRef,
                        'receipt_number' => $receiptNumber,
                        'member_id' => $savingsAccount->member_id,
                        'type' => 'charge',
                        'amount' => $chargeAmount,
                        'payment_mode' => $validated['payment_mode'] ?? null,
                        'deposited_by' => 'System (Charge)',
                        'transaction_date' => $validated['deposit_date'],
                        'account_id' => $savingsAccount->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => $chargeNarration,
                        'charge_name' => $chargeName,
                        'is_reversible' => $charge['is_reversible'] ?? true,
                        'grouped_with' => $receiptNumber,
                        'created_by' => Auth::id(),
                        'branch_id' => $branchId,
                    ]);

                    $this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
                }
            }

            $savingsAccount->save();

            $depositTxn = Transaction::create([
                'reference' => $transactionRef,
                'receipt_number' => $receiptNumber,
                'member_id' => $savingsAccount->member_id,
                'type' => 'deposit',
                'amount' => $validated['amount'],
                'payment_mode' => $validated['payment_mode'] ?? null,
                'deposited_by' => $validated['deposited_by'] ?? null,
                'transaction_date' => $validated['deposit_date'],
                'account_id' => $savingsAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => $validated['narration'] ?? null,
                'created_by' => Auth::id(),
                'branch_id' => $branchId,
            ]);

            $this->savingsJournal->postDeposit($depositTxn, $savingsAccount);

            $this->chargeApplication->applyForSavingsEvent(
                savingsAccountId: $savingsAccount->id,
                eventType: 'deposit',
                transactionAmount: (float) $depositTxn->amount,
                transactionId: $depositTxn->id,
                actorId: $depositTxn->created_by ?? auth()->id(),
            );

            // Collect any pending registration charges now that funds are available.
            if ($member) {
                app(MemberChargeService::class)->collectPendingCharges(
                    $member,
                    $savingsAccount,
                    $this->savingsJournal,
                );
            }
        });

        return response()->json(['success' => true, 'message' => 'Deposit successful.']);
    }

    /**
     * Handle savings withdrawal.
     */
    public function withdraw(Request $request, SavingsAccount $savingsAccount)
    {
        $member = $savingsAccount->member;
        if ($member && $member->status !== 'active') {
            return response()->json([
                'message' => 'Member account is pending approval and cannot withdraw.',
                'errors' => ['member' => ['This member must be approved before any transactions can be made.']],
            ], 403);
        }

        $validated = $request->validate([
            'deposit_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'deposited_by' => 'nullable|string|max:255',
            'payment_mode' => 'required|string|max:50',
            'narration' => 'nullable|string',
        ]);

        // Minimum balance check
        if ($savingsAccount->consider_min_balance) {
            $savingsAccount->load('savingsProduct');
            $minimumBalance = (float) ($savingsAccount->savingsProduct?->minimum_balance ?? 0);
            $withdrawable = (float) $savingsAccount->balance - $minimumBalance;
            if ($validated['amount'] > $withdrawable) {
                $fmt = number_format($minimumBalance, 2);
                $fmtMax = number_format(max($withdrawable, 0), 2);

                return response()->json([
                    'message' => "Withdrawal would breach the minimum balance of UGX {$fmt}. Maximum you can withdraw is UGX {$fmtMax}.",
                    'errors' => ['amount' => ["Maximum withdrawable amount is UGX {$fmtMax} (minimum balance: UGX {$fmt})."]],
                ], 422);
            }
        }

        // Sufficient balance check
        if ($validated['amount'] > (float) $savingsAccount->balance) {
            return response()->json([
                'message' => 'Insufficient account balance.',
                'errors' => ['amount' => ['Withdrawal amount exceeds account balance.']],
            ], 422);
        }

        $receiptNumber = $this->generateReceiptNumber();
        $transactionRef = $this->generateTransactionReference();
        $branchId = BranchContext::actingBranchId();

        DB::connection('tenant')->transaction(function () use ($savingsAccount, $validated, $receiptNumber, $transactionRef, $branchId) {
            $savingsAccount->balance -= $validated['amount'];
            $savingsAccount->loadMissing('savingsProduct.charges');

            // Apply withdrawal charges
            $withdrawAmount = (float) $validated['amount'];
            $selectedCharges = $this->resolveSelectedCharges($savingsAccount);
            foreach ($selectedCharges as $charge) {
                $chargeType = $charge['type'] ?? '';
                if ($chargeType !== 'withdraw' && $chargeType !== 'withdrawal') {
                    continue;
                }

                // Respect amount range if defined
                $min = (float) ($charge['minimum_amount'] ?? 0);
                $max = (float) ($charge['maximum_amount'] ?? 0);
                if ($max > 0 && ($withdrawAmount < $min || $withdrawAmount > $max)) {
                    continue;
                }

                $chargeAmount = ($charge['charge_type'] ?? '') === 'percentage'
                    ? round($withdrawAmount * ($charge['amount'] ?? 0) / 100, 2)
                    : (float) ($charge['amount'] ?? 0);

                if ($chargeAmount > 0) {
                    $savingsAccount->balance -= $chargeAmount;
                    $chargeRef = 'CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
                    $chargeName = $charge['name'] ?? null;
                    $chargeNarration = 'Withdrawal Charge'.($chargeName ? ': '.$chargeName : '');

                    $chargeTxn = Transaction::create([
                        'reference' => $chargeRef,
                        'receipt_number' => $receiptNumber,
                        'member_id' => $savingsAccount->member_id,
                        'type' => 'charge',
                        'amount' => $chargeAmount,
                        'payment_mode' => $validated['payment_mode'] ?? null,
                        'deposited_by' => 'System (Charge)',
                        'transaction_date' => $validated['deposit_date'],
                        'account_id' => $savingsAccount->id,
                        'account_type' => SavingsAccount::class,
                        'narration' => $chargeNarration,
                        'charge_name' => $chargeName,
                        'is_reversible' => $charge['is_reversible'] ?? true,
                        'grouped_with' => $receiptNumber,
                        'created_by' => Auth::id(),
                        'branch_id' => $branchId,
                    ]);

                    $this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
                }
            }

            $savingsAccount->save();

            $withdrawalTxn = Transaction::create([
                'reference' => $transactionRef,
                'receipt_number' => $receiptNumber,
                'member_id' => $savingsAccount->member_id,
                'type' => 'withdrawal',
                'amount' => $validated['amount'],
                'payment_mode' => $validated['payment_mode'] ?? null,
                'deposited_by' => $validated['deposited_by'] ?? null,
                'transaction_date' => $validated['deposit_date'],
                'account_id' => $savingsAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => $validated['narration'] ?? null,
                'created_by' => Auth::id(),
                'branch_id' => $branchId,
            ]);

            $this->savingsJournal->postWithdrawal($withdrawalTxn, $savingsAccount);

            $this->chargeApplication->applyForSavingsEvent(
                savingsAccountId: $savingsAccount->id,
                eventType: 'withdraw',
                transactionAmount: (float) $withdrawalTxn->amount,
                transactionId: $withdrawalTxn->id,
                actorId: $withdrawalTxn->created_by ?? auth()->id(),
            );
        });

        return response()->json(['success' => true, 'message' => 'Withdrawal successful.']);
    }

    /**
     * Update custom fee overrides for a savings account.
     */
    public function updateCustomFees(Request $request, SavingsAccount $savingsAccount)
    {
        $validated = $request->validate([
            'custom_monthly_fee_enabled' => ['required', 'boolean'],
            'custom_monthly_fee_type' => ['nullable', 'string', 'in:percentage,amount'],
            'custom_monthly_fee_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $savingsAccount->update([
                'custom_monthly_fee_enabled' => $validated['custom_monthly_fee_enabled'],
                'custom_monthly_fee_type' => $validated['custom_monthly_fee_enabled'] ? $validated['custom_monthly_fee_type'] : null,
                'custom_monthly_fee_amount' => $validated['custom_monthly_fee_enabled'] ? $validated['custom_monthly_fee_amount'] : null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Custom fee settings updated successfully.',
                'data' => new SavingsAccountResource($savingsAccount),
            ]);
        } catch (\Exception $e) {
            Log::error('SavingsAccount custom fees update failed: '.$e->getMessage());

            return response()->json(['message' => 'Failed to update custom fee settings.'], 500);
        }
    }

    /**
     * Resolve charges for a savings account to an array of charge objects.
     *
     * Priority:
     *  1. selected_charges already contains full objects  → use as-is
     *  2. selected_charges contains IDs                   → look up from product
     *  3. selected_charges is empty                       → fall back to ALL product charges
     */
    private function resolveSelectedCharges(SavingsAccount $account): array
    {
        $account->loadMissing('savingsProduct.charges');
        $productCharges = $account->savingsProduct?->charges ?? collect();

        $stored = $account->selected_charges ?? [];

        if (! empty($stored)) {
            // Already full objects
            if (is_array($stored[0] ?? null) && isset($stored[0]['type'])) {
                return $stored;
            }

            // Legacy IDs — filter product charges to only selected ones
            $productCharges = $productCharges->whereIn('id', $stored);
        }
        // Empty selected_charges → apply all product charges

        return $productCharges
            ->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name ?? null,
                'type' => $c->type,
                'charge_type' => $c->charge_type,
                'amount' => (float) $c->amount,
                'minimum_amount' => (float) ($c->minimum_amount ?? 0),
                'maximum_amount' => (float) ($c->maximum_amount ?? 0),
                'is_reversible' => $c->is_reversible ?? true,
            ])
            ->values()
            ->toArray();
    }

    private function generateReceiptNumber(): string
    {
        $date = date('Ymd');
        for ($i = 0; $i < 5; $i++) {
            $candidate = "RCPT-{$date}-".mt_rand(10000, 99999);
            $exists = Transaction::where('receipt_number', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return "RCPT-{$date}-".uniqid();
    }

    private function generateTransactionReference(): string
    {
        $date = date('Ymd');
        for ($i = 0; $i < 5; $i++) {
            $candidate = "TXN-{$date}-".mt_rand(10000, 99999);
            $exists = Transaction::where('reference', $candidate)->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        return "TXN-{$date}-".uniqid();
    }

    /**
     * Apply a manual ad-hoc charge to a savings account.
     */
    public function charge(Request $request, SavingsAccount $savingsAccount)
    {
        $member = $savingsAccount->member;
        if ($member && $member->status !== 'active') {
            return response()->json([
                'message' => 'Member account is not active.',
                'errors' => ['member' => ['This member must be active to apply charges.']],
            ], 403);
        }

        $validated = $request->validate([
            'charge_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'charge_date' => 'required|date',
            'narration' => 'nullable|string|max:500',
            'is_reversible' => 'boolean',
        ]);

        if ($validated['amount'] > (float) $savingsAccount->balance) {
            return response()->json([
                'message' => 'Insufficient account balance to apply this charge.',
                'errors' => ['amount' => ['Charge amount exceeds account balance.']],
            ], 422);
        }

        $branchId = BranchContext::actingBranchId();

        DB::connection('tenant')->transaction(function () use ($savingsAccount, $validated, $branchId) {
            $savingsAccount->balance -= $validated['amount'];
            $savingsAccount->save();

            $ref = 'CHG-'.date('Ymd').'-'.mt_rand(10000, 99999);
            $narration = $validated['narration'] ?? 'Manual charge: '.$validated['charge_name'];

            // Manual charges always use the default income account (GL 4230).
            $glCreditAccountId = ChartOfAccount::where('gl_code', '4230')->where('is_active', true)->value('id');

            $chargeTxn = Transaction::create([
                'reference' => $ref,
                'member_id' => $savingsAccount->member_id,
                'type' => 'charge',
                'amount' => $validated['amount'],
                'payment_mode' => 'system',
                'deposited_by' => 'System (Manual Charge)',
                'transaction_date' => $validated['charge_date'],
                'account_id' => $savingsAccount->id,
                'account_type' => SavingsAccount::class,
                'narration' => $narration,
                'charge_name' => $validated['charge_name'],
                'gl_credit_account_id' => $glCreditAccountId,
                'is_reversible' => $validated['is_reversible'] ?? true,
                'created_by' => Auth::id(),
                'branch_id' => $branchId,
            ]);

            $this->savingsJournal->postCharge($chargeTxn, $savingsAccount);
        });

        return response()->json(['success' => true, 'message' => 'Charge applied successfully.']);
    }
}
