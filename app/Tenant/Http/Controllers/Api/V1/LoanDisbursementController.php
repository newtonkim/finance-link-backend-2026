<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\DisburseLoanApplicationRequest;
use App\Tenant\Http\Resources\LoanApplicationListResource;
use App\Tenant\Http\Resources\LoanResource;
use App\Tenant\Modules\Loans\Contracts\LoanDisbursementServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanDisbursementController extends Controller
{
    public function __construct(
        protected LoanDisbursementServiceInterface $service,
    ) {}

    /**
     * Approved applications awaiting finance disbursement action.
     */
    public function pending(Request $request): JsonResponse
    {
        $query = LoanApplication::query()
            ->where('status', LoanApplication::STATUS_APPROVED)
            ->with([
                'member',
                'loanProduct.disbursementAccount',
                'loanProduct.portfolioAccount',
                'loanProduct.interestIncomeAccount',
            ])
            ->orderByDesc('approved_at');

        if ($branchId = $request->header('X-Acting-Branch-Id')) {
            $query->where('branch_id', (int) $branchId);
        }

        $perPage = min((int) ($request->query('per_page', 25)), 100);
        $paginated = $query->paginate($perPage);

        return response()->json([
            'data' => LoanApplicationListResource::collection($paginated->items()),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    public function disburse(DisburseLoanApplicationRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        /** @var int|null $actorId */
        $actorId = auth('tenant')->id() ?? auth()->id() ?? 1;

        $loan = $this->service->disburse(
            $loanApplication,
            $request->validated(),
            $actorId,
        );

        $loan->load(['member', 'loanProduct', 'loanOfficer', 'disbursedBy', 'schedules']);

        return response()->json([
            'message' => 'Loan disbursed successfully.',
            'data' => new LoanResource($loan),
        ], 201);
    }
}
