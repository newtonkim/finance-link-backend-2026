<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Requests\Tenant\CancelLoanApplicationRequest;
use App\Http\Requests\Tenant\EligibilityCheckRequest;
use App\Http\Requests\Tenant\StoreLoanApplicationRequest;
use App\Http\Requests\Tenant\UpdateLoanApplicationRequest;
use App\Models\Member;
use App\Models\Staff;
use App\Tenant\Http\Resources\LoanApplicationListResource;
use App\Tenant\Http\Resources\LoanApplicationResource;
use App\Tenant\Modules\Loans\Contracts\LoanApplicationServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanEligibilityServiceInterface;
use App\Tenant\Modules\Loans\Contracts\LoanTimelineServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use App\Tenant\Modules\Loans\Services\LoanPermissionsService;
use App\Tenant\Services\TenantLoanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoanApplicationController extends TenantLoanService
{
    public function __construct(
        protected LoanApplicationServiceInterface $service,
        protected LoanEligibilityServiceInterface $eligibility,
        protected LoanTimelineServiceInterface $timeline,
        protected LoanPermissionsService $permissions,
    ) {}

    public function loan_application_download_template()
    {
        return $this->Response(['data' => self::loanApplicationDownloadTemplate()]);
    }

    public function loan_transactions_upload_template()
    {
        return $this->Response(['data' => self::loanTransactionsUploadTemplate()]);
    }

    public function loan_application_upload_template()
    {
        return $this->Response(['data' => self::loanApplicationUploadTemplate()]);
    }

    public function loan_repayment_upload_template()
    {
        return $this->Response(['data' => self::loanRepaymentUploadTemplate()]);
    }

    public function loan_transactions_download_template()
    {
        return $this->Response(['data' => self::loanTransactionsTemplate()]);
    }

    public function loan_repayment_download_template()
    {
        return $this->Response(['data' => self::loanRepaymentTemplate()]);
    }

    public function get_loan_applications_transactions()
    {
        return $this->Response(['data' => self::getLoanApplicationsTransactions()]);
    }

    public function get_loan_applications_list()
    {
        return $this->Response(['data' => self::getLoanApplicationsList()]);
    }

    public function save_guarantors_none_member()
    {
        return $this->Response(['data' => self::saveGuarantorsNoneMember()]);
    }

    public function save_guarantors()
    {
        return $this->Response(['data' => self::saveGuarantors()]);
    }

    public function eligibilityCheck(EligibilityCheckRequest $request): JsonResponse
    {
        $member = Member::findOrFail($request->integer('member_id'));
        $product = LoanProduct::findOrFail($request->integer('loan_product_id'));

        $result = $this->eligibility->evaluate(
            $member,
            $product,
            (float) $request->input('requested_amount'),
            (int) $request->input('requested_term'),
        );

        return response()->json(['data' => $result->toArray()]);
    }

    public function memberSearch(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search', ''));

        $members = Member::select('id', 'name', 'code', 'phone')
            ->with(['savingsAccounts' => fn ($q) => $q->select('id', 'member_id', 'account_no', 'balance')])
            ->when($search, fn ($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('phone', 'ilike', "%{$search}%");
            }))
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(function ($m) {
                $account = $m->savingsAccounts->first();

                return [
                    'id' => $m->id,
                    'name' => $m->name,
                    'member_no' => $m->code,
                    'phone' => $m->phone,
                    'savings_account' => $account ? [
                        'account_no' => $account->account_no,
                        'balance' => $account->balance,
                    ] : null,
                ];
            });

        return response()->json(['data' => $members]);
    }

    public function index(Request $request)
    {
        $applications = $this->service->list(
            $request->only('status', 'branch_id', 'member_search', 'loan_product_id', 'date_from', 'date_to')
        );

        return LoanApplicationListResource::collection($applications);
    }

    public function store(StoreLoanApplicationRequest $request)
    {
        $validated = $request->validated();
        $branchId = $validated['branch_id']
            ?? ($request->header('X-Acting-Branch-Id') ? (int) $request->header('X-Acting-Branch-Id') : null);

        $application = $this->service->create(array_merge(
            $validated,
            ['created_by' => auth('tenant')->id(), 'branch_id' => $branchId]
        ));

        return response()->json([
            'message' => 'Loan Application Created Successfully.',
            'data' => new LoanApplicationResource($this->withRelations($application)),
        ], 201);
    }

    public function show(LoanApplication $loanApplication)
    {
        $staff = $this->currentStaff();
        $application = $this->withRelations($loanApplication);

        return response()->json([
            'data' => new LoanApplicationResource($application),
            'permitted_actions' => $this->permissions->forUser($application, $staff),
        ]);
    }

    public function update(UpdateLoanApplicationRequest $request, LoanApplication $loanApplication)
    {
        $validated = $request->validated();
        if (! isset($validated['branch_id']) && $request->header('X-Acting-Branch-Id')) {
            $validated['branch_id'] = (int) $request->header('X-Acting-Branch-Id');
        }

        $this->service->update($loanApplication, array_merge(
            $validated,
            ['updated_by' => auth('tenant')->id()]
        ));

        return response()->json([
            'message' => 'Loan Application Updated Successfully.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    public function submit(LoanApplication $loanApplication)
    {
        $this->service->submit($loanApplication);

        return response()->json([
            'message' => 'Loan Application Submitted Successfully.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    public function cancel(CancelLoanApplicationRequest $request, LoanApplication $loanApplication)
    {
        $this->service->cancel($loanApplication, $request->validated('reason'));

        return response()->json([
            'message' => 'Loan Application Cancelled Successfully.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    public function reopen(LoanApplication $loanApplication)
    {
        $this->service->reopen($loanApplication);

        return response()->json([
            'message' => 'Loan Application Reopened as Draft.',
            'data' => new LoanApplicationResource($this->withRelations($loanApplication->fresh())),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $branchId = $request->header('X-Acting-Branch-Id')
            ? (int) $request->header('X-Acting-Branch-Id')
            : null;

        $counts = LoanApplication::on('tenant')
            ->when($branchId, function ($query) use ($branchId) {
                $query->where(function ($q) use ($branchId) {
                    $q->where('branch_id', $branchId)
                        ->orWhereNull('branch_id');
                });
            })
            ->selectRaw("
                SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) AS submitted,
                SUM(CASE WHEN status = 'under_review' THEN 1 ELSE 0 END) AS under_review,
                SUM(CASE WHEN status = 'awaiting_documents' THEN 1 ELSE 0 END) AS awaiting_documents,
                SUM(CASE WHEN status = 'recommended' THEN 1 ELSE 0 END) AS recommended,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN status NOT IN ('cancelled', 'rejected', 'disbursed') THEN 1 ELSE 0 END) AS total_active
            ")
            ->first();

        return response()->json(['data' => $counts]);
    }

    public function timeline(LoanApplication $loanApplication)
    {
        $loanApplication->load('createdBy');

        $events = $this->timeline->getTimeline($loanApplication);

        return response()->json(['data' => $events]);
    }

    private function withRelations(LoanApplication $application): LoanApplication
    {
        $application->load([
            'member',
            'loanProduct.charges',
            'loanOfficer',
            'appraisedBy',
            'recommendedBy',
            'approvedBy',
            'rejectedBy',
            'approvals.approver',
            'statusHistory.changedBy',
            'createdBy',
            'disbursedLoan.schedules',
            'disbursedLoan.loanOfficer',
            'disbursedLoan.disbursedBy',
            'disbursedLoan.appliedCharges',
        ]);

        return $application;
    }

    /**
     * Get the current authenticated staff member.
     */
    private function currentStaff(): Staff
    {
        $userId = auth()->id() ?? auth('tenant')->id();

        return Staff::findOrFail($userId);
    }
}
