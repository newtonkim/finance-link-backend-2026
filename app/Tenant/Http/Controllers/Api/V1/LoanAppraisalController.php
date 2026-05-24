<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AppraiseLoanApplicationRequest;
use App\Http\Requests\Tenant\RejectLoanApplicationRequest;
use App\Http\Requests\Tenant\RequestDocumentsRequest;
use App\Http\Requests\Tenant\ReturnForCorrectionRequest;
use App\Tenant\Http\Resources\LoanApplicationResource;
use App\Tenant\Modules\Loans\Contracts\LoanAppraisalServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;

class LoanAppraisalController extends Controller
{
    public function __construct(
        protected LoanAppraisalServiceInterface $service,
    ) {}

    /**
     * Take a submitted application into review (submitted → under_review).
     */
    public function takeForReview(LoanApplication $loanApplication)
    {
        $this->service->takeForReview($loanApplication);

        return response()->json([
            'message' => 'Application taken for review.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Record appraisal and recommend the application (under_review → recommended).
     */
    public function appraise(AppraiseLoanApplicationRequest $request, LoanApplication $loanApplication)
    {
        $this->service->appraise($loanApplication, $request->validated());

        return response()->json([
            'message' => 'Application appraised and recommended.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Flag the application as awaiting additional documents (under_review → awaiting_documents).
     */
    public function requestDocuments(RequestDocumentsRequest $request, LoanApplication $loanApplication)
    {
        $this->service->requestDocuments($loanApplication, $request->validated('note'));

        return response()->json([
            'message' => 'Application flagged as awaiting documents.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Return the application to draft for corrections (under_review → draft).
     */
    public function returnForCorrection(ReturnForCorrectionRequest $request, LoanApplication $loanApplication)
    {
        $this->service->returnForCorrection($loanApplication, $request->validated('reason'));

        return response()->json([
            'message' => 'Application returned for corrections.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Resume review after documents were provided (awaiting_documents → under_review).
     */
    public function resumeReview(LoanApplication $loanApplication)
    {
        $this->service->resumeReview($loanApplication);

        return response()->json([
            'message' => 'Review resumed.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    /**
     * Reject the application at appraisal stage (under_review → rejected).
     */
    public function reject(RejectLoanApplicationRequest $request, LoanApplication $loanApplication)
    {
        $this->service->reject($loanApplication, $request->validated('reason'));

        return response()->json([
            'message' => 'Application rejected.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
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
            'statusHistory.changedBy',
            'createdBy',
        ]);

        return $application;
    }
}
