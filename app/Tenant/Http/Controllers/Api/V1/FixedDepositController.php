<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsInterestPosting;
use App\Tenant\Modules\Savings\Services\FixedDepositInterestService;
use App\Tenant\Modules\Savings\Services\FixedDepositMaturityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedDepositController extends Controller
{
    public function __construct(
        protected FixedDepositInterestService $interestService,
        protected FixedDepositMaturityService $maturityService,
    ) {}

    /**
     * GET /savings/fixed-deposits
     * List all FD accounts with maturity and interest dates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SavingsAccount::on('tenant')
            ->with(['member', 'savingsProduct'])
            ->where('account_type', 'fixed')
            ->orderByDesc('created_at');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn ($q) => $q->whereHas('member', fn ($m) => $m->where('name', 'like', "%{$search}%")
                ->orWhere('member_number', 'like', "%{$search}%")
            )
                ->orWhere('account_no', 'like', "%{$search}%")
            );
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $paginated = $query->paginate(20);

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * POST /savings/fixed-deposits/post-interest
     * Manager month-end sweep — posts all due interest.
     */
    public function postInterest(Request $request): JsonResponse
    {
        $actorId = auth()->id() ?? 1;
        $summary = $this->interestService->runMonthEndSweep($actorId);

        return response()->json([
            'message' => 'Interest posting complete.',
            'data' => $summary,
        ]);
    }

    /**
     * POST /savings-accounts/{id}/maturity/process
     * Officer: process a matured FD (rollover, convert, or close).
     */
    public function processMaturity(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'in:rollover,convert,close'],
        ]);

        $account = SavingsAccount::on('tenant')->findOrFail($id);

        if ($account->status !== 'matured') {
            return response()->json(['message' => 'Account is not in matured status.'], 422);
        }

        $this->maturityService->processManualAction($account, $request->input('action'), auth()->id() ?? 1);

        return response()->json(['message' => 'Maturity processed successfully.']);
    }

    /**
     * GET /savings-accounts/{id}/interest-postings
     * Posting history for one FD account.
     */
    public function interestPostings(int $id): JsonResponse
    {
        $postings = SavingsInterestPosting::on('tenant')
            ->where('savings_account_id', $id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $postings]);
    }
}
