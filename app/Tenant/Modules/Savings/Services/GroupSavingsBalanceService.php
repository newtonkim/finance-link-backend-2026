<?php

namespace App\Tenant\Modules\Savings\Services;

use App\Tenant\Modules\Transactions\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Moves money in and out of a group savings account outside the teller screens:
 * a group guarantee being drawn on, and the borrower paying the group back.
 *
 * A group account's balance is the group's, but each member's contribution is
 * tracked in savings_group_members.balance. Money taken from the group is taken
 * from its members in proportion to what each has in it, and money paid back is
 * returned to the members it was taken from, in the same proportion.
 *
 * This only moves balances and records the transaction. The journal entry belongs
 * to the caller, which knows what the money is for.
 */
class GroupSavingsBalanceService
{
    /**
     * How $amount would be taken from a group's members: in proportion to what each
     * has in the group, never more than they have.
     *
     * @return array<int, float> savings_group_members.id => amount
     */
    public function memberSplit(int $savingsGroupId, float $amount): array
    {
        $members = DB::connection('tenant')->table('savings_group_members')
            ->where('savings_group_id', $savingsGroupId)
            ->whereNull('deleted_at')
            ->where('balance', '>', 0)
            ->orderBy('id')
            ->pluck('balance', 'id')
            ->map(fn ($b) => (float) $b)
            ->all();

        return $this->spread($amount, $members, $members);
    }

    /**
     * Take $amount out of a group account and, per $split, out of its members.
     *
     * @param  array<int, float>  $split  savings_group_members.id => amount, from memberSplit()
     *
     * @throws ValidationException if the account does not hold $amount
     */
    public function debit(int $groupAccountId, float $amount, array $split, string $type, string $narration, string $date, ?int $actorId): Transaction
    {
        $account = $this->lockAccount($groupAccountId);

        if ((float) $account->balance + 0.001 < $amount) {
            throw ValidationException::withMessages(['amount' => ['The group savings account does not hold enough.']]);
        }

        return $this->move($account, -$amount, $split, $type, $narration, $date, $actorId);
    }

    /**
     * Pay $amount into a group account and, per $split, back to its members.
     *
     * @param  array<int, float>  $split  savings_group_members.id => amount
     */
    public function credit(int $groupAccountId, float $amount, array $split, string $narration, string $date, ?string $paymentMode, ?int $actorId): Transaction
    {
        $account = $this->lockAccount($groupAccountId);

        return $this->move($account, $amount, $split, 'deposit', $narration, $date, $actorId, $paymentMode);
    }

    /**
     * Split $total across keys in proportion to $weights, capped at $caps; cents
     * lost to rounding go to the first keys with room.
     *
     * @param  array<int, float>  $weights
     * @param  array<int, float>  $caps
     * @return array<int, float>
     */
    public function spread(float $total, array $weights, array $caps): array
    {
        $weightSum = array_sum($weights);
        if ($total <= 0 || $weightSum <= 0) {
            return [];
        }

        $shares = [];
        foreach ($weights as $key => $weight) {
            $shares[$key] = min(floor($total * $weight / $weightSum * 100) / 100, $caps[$key] ?? INF);
        }

        $left = round($total - array_sum($shares), 2);
        foreach ($shares as $key => $share) {
            if ($left <= 0) {
                break;
            }
            $room = round(($caps[$key] ?? INF) - $share, 2);
            $add = min($left, $room);
            $shares[$key] = round($share + $add, 2);
            $left = round($left - $add, 2);
        }

        return array_filter($shares, fn ($s) => $s > 0);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function lockAccount(int $groupAccountId): object
    {
        $account = DB::connection('tenant')->table('group_savings_accounts')
            ->where('id', $groupAccountId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first(['id', 'balance', 'savings_group_id', 'branch_id']);

        if (! $account) {
            throw ValidationException::withMessages(['group_savings_account_id' => ['Group savings account not found.']]);
        }

        return $account;
    }

    /** @param  array<int, float>  $split */
    private function move(object $account, float $delta, array $split, string $type, string $narration, string $date, ?int $actorId, ?string $paymentMode = null): Transaction
    {
        $db = DB::connection('tenant');
        $before = (float) $account->balance;

        $db->table('group_savings_accounts')->where('id', $account->id)->update([
            'balance' => round($before + $delta, 2),
            'updated_at' => now(),
        ]);

        $sign = $delta < 0 ? -1 : 1;
        foreach ($split as $groupMemberId => $share) {
            $db->table('savings_group_members')
                ->where('id', $groupMemberId)
                ->where('savings_group_id', $account->savings_group_id)
                ->update([
                    'balance' => DB::raw('GREATEST(0, COALESCE(balance, 0) + '.($sign * round((float) $share, 2)).')'),
                    'updated_at' => now(),
                ]);
        }

        return Transaction::create([
            'reference' => 'GSR-'.strtoupper(Str::random(8)),
            'type' => $type,
            'account_type' => $type,
            'amount' => abs($delta),
            'charge_amount' => 0,
            'group_savings_account_id' => $account->id,
            'amount_before_transactions' => $before,
            'payment_mode' => $paymentMode ?? 'savings_account',
            'transaction_date' => $date,
            'narration' => $narration,
            'created_by' => $actorId,
            'branch_id' => $account->branch_id,
        ]);
    }
}
