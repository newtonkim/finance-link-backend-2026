<?php

namespace App\Tenant\Services\TenantSavingsAcountServices\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait ManagesGroupWithdrawalApprovals
{
    private function groupWithdrawalRequiredApprovals($groupId): int
    {
        $group = DB::table('savings_groups')->where('id', $groupId)->first(['withdrawal_required_approvals']);

        return (int) ($group->withdrawal_required_approvals ?? 0);
    }

    /**
     * Persist a pending withdrawal request instead of moving money. The request
     * waits until the required number of designated group approvers sign off.
     */
    private function createGroupWithdrawalRequestRow($currentBalance, $req, $chargedAmount, int $required, $getMemberGroup)
    {
        $amount = (float) $req['amount'];

        // Reject upfront if the member cannot cover it, so we never queue an
        // impossible withdrawal.
        $projected = (float) ($getMemberGroup->balance ?? 0) - ($amount + (float) $chargedAmount);
        if ($projected < 0) {
            throw new \Exception('Insufficient member group balance.');
        }

        $id = DB::table('group_withdrawal_requests')->insertGetId([
            'savings_group_id' => $currentBalance->savings_group_id,
            'group_savings_account_id' => $currentBalance->id,
            'member_id' => $req['member_id'],
            'amount' => $amount,
            'charge_amount' => (float) $chargedAmount,
            'narration' => $req['narration'] ?? null,
            'payment_mode_id' => $req['payment_mode_id'] ?? null,
            'status' => 'pending',
            'required_approvals' => $required,
            'requested_by' => Auth::check() ? Auth::id() : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'status' => 'pending_approval',
            'request_id' => $id,
            'required_approvals' => $required,
            'message' => "Withdrawal submitted for approval. It needs {$required} approver(s) to sign off before funds are released.",
        ];
    }

    /**
     * Record a designated approver's decision on a pending withdrawal request.
     * When the approval quorum is reached the withdrawal is executed; a single
     * rejection kills the request.
     */
    public function actOnGroupWithdrawalRequest()
    {
        request()->validate([
            'request_id' => ['required', 'numeric'],
            'approver_member_id' => ['required', 'numeric'],
            'decision' => ['required', 'string', 'in:approved,rejected'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->TryCatch(function () {
            return $this->transaction(function () {
                $req = request()->all();

                $reqRow = DB::table('group_withdrawal_requests')
                    ->where('id', $req['request_id'])
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();
                if (! $reqRow) {
                    throw new \Exception('Withdrawal request not found.');
                }
                if ($reqRow->status !== 'pending') {
                    throw new \Exception("This request is already {$reqRow->status}.");
                }

                $approverId = $req['approver_member_id'];
                if ((int) $approverId === (int) $reqRow->member_id) {
                    throw new \Exception('A member cannot approve their own withdrawal.');
                }

                // Approver must be an active, designated approver of this group.
                $approver = DB::table('savings_group_members as sgm')
                    ->join('members as mb', 'mb.id', '=', 'sgm.member_id')
                    ->where('sgm.savings_group_id', $reqRow->savings_group_id)
                    ->where('sgm.member_id', $approverId)
                    ->where('sgm.is_approver', true)
                    ->whereNull('sgm.deleted_at')
                    ->where('mb.status', 'active')
                    ->first(['sgm.id']);
                if (! $approver) {
                    throw new \Exception('You are not a designated approver for this group.');
                }

                $alreadyVoted = DB::table('group_withdrawal_approvals')
                    ->where('group_withdrawal_request_id', $reqRow->id)
                    ->where('approver_member_id', $approverId)
                    ->exists();
                if ($alreadyVoted) {
                    throw new \Exception('You have already voted on this request.');
                }

                DB::table('group_withdrawal_approvals')->insert([
                    'group_withdrawal_request_id' => $reqRow->id,
                    'approver_member_id' => $approverId,
                    'decision' => $req['decision'],
                    'comment' => $req['comment'] ?? null,
                    'acted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // A single rejection terminates the request.
                if ($req['decision'] === 'rejected') {
                    DB::table('group_withdrawal_requests')->where('id', $reqRow->id)->update([
                        'status' => 'rejected',
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return ['status' => 'rejected', 'message' => 'Withdrawal request rejected.'];
                }

                $approvals = DB::table('group_withdrawal_approvals')
                    ->where('group_withdrawal_request_id', $reqRow->id)
                    ->where('decision', 'approved')
                    ->count();

                if ($approvals < (int) $reqRow->required_approvals) {
                    return [
                        'status' => 'pending',
                        'approvals' => $approvals,
                        'required_approvals' => (int) $reqRow->required_approvals,
                        'message' => "Approval recorded ({$approvals}/{$reqRow->required_approvals}). Awaiting more approvals.",
                    ];
                }

                // Quorum reached — execute the actual withdrawal, bypassing the
                // approval gate via the _approved_request_id flag.
                request()->merge([
                    'group_account_id' => (string) $reqRow->group_savings_account_id,
                    'member_id' => (string) $reqRow->member_id,
                    'amount' => (float) $reqRow->amount,
                    'type' => 'withdrawal',
                    'narration' => $reqRow->narration,
                    'payment_mode_id' => $reqRow->payment_mode_id,
                    '_approved_request_id' => $reqRow->id,
                ]);
                $this->groupSavingAccountDepositWithdrawal();

                DB::table('group_withdrawal_requests')->where('id', $reqRow->id)->update([
                    'status' => 'executed',
                    'resolved_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['status' => 'executed', 'message' => 'Withdrawal approved and funds released.'];
            });
        });
    }

    /**
     * Requester (or an admin) cancels their own pending withdrawal request.
     */
    public function cancelGroupWithdrawalRequest()
    {
        request()->validate(['request_id' => ['required', 'numeric']]);

        return $this->TryCatch(function () {
            $reqRow = DB::table('group_withdrawal_requests')
                ->where('id', request('request_id'))
                ->whereNull('deleted_at')
                ->first();
            if (! $reqRow) {
                throw new \Exception('Withdrawal request not found.');
            }
            if ($reqRow->status !== 'pending') {
                throw new \Exception("This request is already {$reqRow->status}.");
            }

            DB::table('group_withdrawal_requests')->where('id', $reqRow->id)->update([
                'status' => 'cancelled',
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'cancelled', 'message' => 'Withdrawal request cancelled.'];
        });
    }

    /**
     * List withdrawal requests for a group (optionally filtered by status), with
     * requester details and recorded approver votes.
     */
    public function listGroupWithdrawalRequests()
    {
        request()->validate([
            'group_id' => ['required', 'numeric'],
            'status' => ['nullable', 'string'],
        ]);

        return $this->TryCatch(function () {
            $groupId = request('group_id');
            $status = request('status');

            $requests = DB::table('group_withdrawal_requests as r')
                ->join('members as mb', 'mb.id', '=', 'r.member_id')
                ->leftJoin('group_savings_accounts as g', 'g.id', '=', 'r.group_savings_account_id')
                ->where('r.savings_group_id', $groupId)
                ->whereNull('r.deleted_at')
                ->when($status, fn ($q) => $q->where('r.status', $status))
                ->orderByDesc('r.created_at')
                ->select([
                    'r.id',
                    'r.amount',
                    'r.charge_amount',
                    'r.narration',
                    'r.status',
                    'r.required_approvals',
                    'r.created_at',
                    'r.resolved_at',
                    'r.member_id',
                    'mb.name as requester_name',
                    'mb.code as requester_code',
                    'g.code as account_code',
                    DB::raw('(SELECT COUNT(*) FROM group_withdrawal_approvals a
                        WHERE a.group_withdrawal_request_id = r.id AND a.decision = \'approved\') as approvals_count'),
                ])
                ->get();

            $ids = $requests->pluck('id')->all();
            $votes = empty($ids) ? collect() : DB::table('group_withdrawal_approvals as a')
                ->join('members as mb', 'mb.id', '=', 'a.approver_member_id')
                ->whereIn('a.group_withdrawal_request_id', $ids)
                ->select(['a.group_withdrawal_request_id', 'a.decision', 'a.comment', 'a.acted_at', 'mb.name as approver_name', 'mb.code as approver_code'])
                ->get()
                ->groupBy('group_withdrawal_request_id');

            $requests->each(function ($r) use ($votes) {
                $r->approvals = $votes->get($r->id, collect())->values();
            });

            return $requests;
        });
    }

    /**
     * Flag or unflag a group member as a designated withdrawal approver.
     */
    public function toggleGroupWithdrawalApprover()
    {
        request()->validate([
            'group_id' => ['required', 'numeric'],
            'member_id' => ['required', 'numeric'],
            'is_approver' => ['required', 'boolean'],
            'approver_role' => ['nullable', 'string', 'max:50'],
        ]);

        return $this->TryCatch(function () {
            $updated = DB::table('savings_group_members')
                ->where('savings_group_id', request('group_id'))
                ->where('member_id', request('member_id'))
                ->whereNull('deleted_at')
                ->update([
                    'is_approver' => (bool) request('is_approver'),
                    'approver_role' => request('is_approver') ? request('approver_role') : null,
                    'updated_at' => now(),
                ]);
            if ($updated < 1) {
                throw new \Exception('Group member not found.');
            }

            return ['status' => 'ok', 'message' => 'Approver settings updated.'];
        });
    }
}
