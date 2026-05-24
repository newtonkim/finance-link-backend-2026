<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Tenant\Modules\Transactions\Models\Transaction;
use App\Tenant\Modules\Transactions\Models\TransactionReversal;
use App\Tenant\Modules\Transactions\Services\ReversalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionReversalController extends Controller
{
    public function __construct(protected ReversalService $reversalService) {}

    /**
     * GET /transaction-reversals
     * List reversals — filterable by status (pending, approved, rejected).
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransactionReversal::with([
            'transaction.account',
            'transaction.member',
            'requestedBy',
            'assignedApprover',
            'approvedBy',
        ])->latest();

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $reversals = $query->paginate(20);

        return response()->json($reversals);
    }

    /**
     * POST /savings-accounts/{savingsAccount}/transactions/{transaction}/request-reversal
     * Request a reversal with narration. Applies immediately if approval not required.
     */
    public function store(Request $request, Transaction $transaction): JsonResponse
    {
        $request->validate([
            'narration' => 'required|string|min:5|max:1000',
            'assigned_approver_id' => 'sometimes|nullable|integer|exists:staff,id',
        ]);

        /** @var Staff $staff */
        $staff = auth()->user();

        $reversal = $this->reversalService->requestReversal(
            transaction: $transaction,
            requestedBy: $staff,
            narration: $request->string('narration')->toString(),
            assignedApproverId: $request->integer('assigned_approver_id') ?: null,
        );

        $message = $reversal->status === 'approved'
            ? 'Transaction reversed successfully.'
            : 'Reversal request submitted and is pending approval.';

        return response()->json([
            'message' => $message,
            'reversal' => $this->format($reversal),
        ], 201);
    }

    /**
     * POST /transaction-reversals/{reversal}/approve
     */
    public function approve(TransactionReversal $reversal): JsonResponse
    {
        /** @var Staff $staff */
        $staff = auth()->user();

        $reversal = $this->reversalService->approveReversal($reversal, $staff);

        return response()->json([
            'message' => 'Reversal approved and applied successfully.',
            'reversal' => $this->format($reversal),
        ]);
    }

    /**
     * POST /transaction-reversals/{reversal}/reject
     */
    public function reject(Request $request, TransactionReversal $reversal): JsonResponse
    {
        $request->validate([
            'rejection_reason' => 'required|string|min:5|max:500',
        ]);

        /** @var Staff $staff */
        $staff = auth()->user();

        $reversal = $this->reversalService->rejectReversal(
            reversal: $reversal,
            approver: $staff,
            reason: $request->string('rejection_reason')->toString(),
        );

        return response()->json([
            'message' => 'Reversal request rejected.',
            'reversal' => $this->format($reversal),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function format(TransactionReversal $reversal): array
    {
        $txn = $reversal->transaction;

        return [
            'id' => $reversal->id,
            'status' => $reversal->status,
            'narration' => $reversal->narration,
            'rejection_reason' => $reversal->rejection_reason,
            'approved_at' => $reversal->approved_at?->format('Y-m-d H:i:s'),
            'created_at' => $reversal->created_at?->format('Y-m-d H:i:s'),
            'requested_by' => $reversal->requestedBy?->only(['id', 'name', 'email']),
            'assigned_approver' => $reversal->assignedApprover?->only(['id', 'name', 'email', 'role']),
            'approved_by' => $reversal->approvedBy?->only(['id', 'name', 'email']),
            'transaction' => $txn ? [
                'id' => $txn->id,
                'reference' => $txn->reference,
                'receipt_number' => $txn->receipt_number,
                'type' => $txn->type,
                'amount' => $txn->amount,
                'narration' => $txn->narration,
                'transaction_date' => $txn->transaction_date?->format('Y-m-d'),
                'is_reversed' => $txn->is_reversed,
                'member' => $txn->member?->only(['id', 'name', 'member_number']),
                'account' => $txn->account ? [
                    'id' => $txn->account->id,
                    'account_no' => $txn->account->account_no ?? null,
                    'account_type' => $txn->account->account_type ?? null,
                ] : null,
            ] : null,
        ];
    }
}
