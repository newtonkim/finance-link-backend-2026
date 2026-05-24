<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\ApproveApplicationRequest;
use App\Http\Requests\Tenant\DeclineApplicationRequest;
use App\Tenant\Http\Resources\LoanApplicationApprovalResource;
use App\Tenant\Http\Resources\LoanApplicationResource;
use App\Tenant\Modules\Loans\Contracts\LoanApprovalServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use Illuminate\Http\JsonResponse;

class LoanApprovalController extends Controller
{
    public function __construct(
        protected LoanApprovalServiceInterface $service,
    ) {}

    /**
     * List all approval votes for an application.
     */
    public function index(LoanApplication $loanApplication): JsonResponse
    {
        $approvals = $loanApplication->approvals()->with('approver')->orderBy('created_at')->get();

        return response()->json([
            'data' => LoanApplicationApprovalResource::collection($approvals),
        ]);
    }

    /**
     * Record an approval vote (recommended → approved when threshold met).
     */
    public function approve(ApproveApplicationRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        $approval = $this->service->approve(
            $loanApplication,
            $request->validated('comments')
        );

        $approval->load('approver');

        return response()->json([
            'message' => 'Approval vote recorded.',
            'approval' => new LoanApplicationApprovalResource($approval),
            'application' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Reject the application at the approval stage (recommended → rejected).
     */
    public function decline(DeclineApplicationRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        $approval = $this->service->decline(
            $loanApplication,
            $request->validated('reason')
        );

        $approval->load('approver');

        return response()->json([
            'message' => 'Application declined.',
            'approval' => new LoanApplicationApprovalResource($approval),
            'application' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    private function withRelations(LoanApplication $application): LoanApplication
    {
        $application->load([
            'member',
            'loanProduct',
            'loanOfficer',
            'appraisedBy',
            'recommendedBy',
            'approvedBy',
            'rejectedBy',
            'approvals.approver',
            'statusHistory.changedBy',
            'createdBy',
        ]);

        return $application;
    }
}
