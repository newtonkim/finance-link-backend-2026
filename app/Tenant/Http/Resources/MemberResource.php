<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class MemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'salutation' => $this->salutation,
            'member_number' => $this->member_number,
            'member_type' => $this->member_type,
            'gender' => $this->gender,
            'dob' => $this->dob ? $this->dob->format('Y-m-d') : null,
            'phone' => $this->phone,
            'phone_country' => $this->phone_country,
            'other_contact' => $this->other_contact,
            'other_contact_country' => $this->other_contact_country,
            'mobile_money_number' => $this->mobile_money_number,
            'mobile_money_country' => $this->mobile_money_country,
            'national_id_number' => $this->national_id_number,
            'marital_status' => $this->marital_status,
            'nationality' => $this->nationality,
            'address' => $this->address,
            'next_of_kin' => $this->next_of_kin,
            'next_of_kin_contact' => $this->next_of_kin_contact,
            'next_of_kin_contact_country' => $this->next_of_kin_contact_country,
            'is_shareholder' => $this->is_shareholder,
            'opening_balance' => $this->opening_balance,
            'opening_balance_formatted' => TenantMoney::format($this->opening_balance),
            'status' => $this->status,
            'initial_deposit' => $this->initial_deposit,
            'initial_deposit_formatted' => TenantMoney::format($this->initial_deposit),
            'avatar_url' => $this->avatar_url,
            'joined_at' => $this->joined_at ? $this->joined_at->format('Y-m-d') : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'referred_by' => $this->referred_by,
            'referred_by_name' => $this->referredBy?->name,
            'registered_by' => $this->registered_by,
            'registered_by_name' => $this->registeredBy?->name,
            'approved_by' => $this->approved_by,
            'approved_at' => $this->approved_at?->format('Y-m-d H:i:s'),
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at?->format('Y-m-d H:i:s'),
            'rejection_reason' => $this->rejection_reason,
            'savings_accounts' => $this->whenLoaded('savingsAccounts', function () {
                return $this->savingsAccounts->map(function ($account) {
                    $minimumBalance = $account->consider_min_balance
                        ? (float) ($account->savingsProduct?->minimum_balance ?? 0)
                        : 0;

                    return [
                        'id' => $account->id,
                        // account_no is nullable and largely unpopulated; the SSAC
                        // identifier lives in `code`, so fall back to it.
                        'account_no' => $account->account_no ?: $account->code,
                        'code' => $account->code,
                        'payment_mod' => $account->payment_mod_account_id ?? null,
                        'account_type' => $account->account_type,
                        'balance' => $account->balance,
                        'balance_formatted' => TenantMoney::format($account->balance),
                        'consider_min_balance' => (bool) $account->consider_min_balance,
                        'minimum_balance' => $minimumBalance,
                        'minimum_balance_formatted' => TenantMoney::format($minimumBalance),
                        'withdrawable_amount' => max((float) $account->balance - $minimumBalance, 0),
                        'withdrawable_amount_formatted' => TenantMoney::format(max((float) $account->balance - $minimumBalance, 0)),
                    ];
                });
            }),
            'transactions' => $this->whenLoaded('transactions', function () {
                // transactions.account_type is overloaded with a transaction-type label
                // (e.g. "deposit", "withdrawal"), so the morphTo account() relation cannot
                // resolve and account_no comes back null. Resolve the real account directly
                // by account_id instead.
                // account_no is nullable and largely unpopulated; `code` is the
                // NOT NULL unique identifier, so fall back to it for display.
                $savingsAccounts = DB::connection('tenant')->table('savings_accounts')
                    ->where('member_id', $this->id)
                    ->whereNull('deleted_at')
                    ->get(['id', 'account_no', 'code', 'account_type'])
                    ->keyBy('id');

                return $this->transactions->map(function ($txn) use ($savingsAccounts) {
                    return [
                        'id' => $txn->id,
                        'reference' => $txn->reference,
                        'receipt_number' => $txn->receipt_number,
                        'amount_before_charge' => $txn->deposited_amount_before_charge,
                        'charge_amount' => $txn->charge_amount,
                        'group_savings_account_id' => $txn->group_savings_account_id,
                        'running_balance' => $txn->group_savings_account_id > 0 ? $txn->group_member_account_balance_before_transaction : $txn->amount_before_transactions,
                        'amount' => $txn->amount,
                        'umbrella_code' => $txn->umbrella_code,
                        'amount_after_charge' => DB::table('transactions')->where('umbrella_code', $txn->umbrella_code)->select(

                            $txn->type == 'deposit' ? DB::raw('sum(amount) as amount') : DB::raw('sum(amount+charge_amount) as amount')

                        )->first()->amount,
                        // ->sum('amount+charge_amount'),
                        'amount_formatted' => TenantMoney::format($txn->amount),
                        'type' => $txn->type,
                        'narration' => $txn->narration,
                        'transaction_date' => $txn->transaction_date ? $txn->transaction_date->format('Y-m-d') : null,
                        'created_at' => $txn->created_at ? $txn->created_at->format('Y-m-d H:i:s') : null,
                        'deposited_by' => $txn->deposited_by,
                        'payment_mode' => $txn->payment_mode,
                        'is_reversed' => (bool) $txn->is_reversed,
                        'is_reversible' => (bool) $txn->is_reversible,
                        'charge_name' => $txn->charge_name,
                        'reversal_of' => $txn->reversal_of,
                        'grouped_with' => $txn->grouped_with,
                        'account' => (function () use ($txn, $savingsAccounts) {
                            $acct = $txn->account_id ? ($savingsAccounts[$txn->account_id] ?? null) : null;
                            if ($acct) {
                                return [
                                    'id' => (int) $txn->account_id,
                                    'account_no' => $acct->account_no ?: $acct->code,
                                    'account_type' => $acct->account_type,
                                ];
                            }

                            return $txn->account ? [
                                'id' => $txn->account->id,
                                'account_no' => $txn->account->account_no,
                                'account_type' => $txn->account->account_type,
                            ] : null;
                        })(),
                    ];
                });
            }),
            'Loans' => DB::table('loan_applications')->where('member_id', $this->id)->get(),
            'loans' => LoanResource::collection($this->whenLoaded('loans')),
            'currency_code' => TenantMoney::code(),
        ];
    }
}
