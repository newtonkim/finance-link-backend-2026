<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\GuarantorRecovery;
use App\Tenant\Modules\Loans\Models\GuarantorRecoveryLine;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Services\GuarantorArrearsService;
use App\Tenant\Modules\Loans\Services\GuarantorRecoveryService;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The member's side of guarantor consent: the guarantees they have been asked to
 * give, and accepting or declining them. A member only ever sees and answers
 * guarantees where they themselves are the individual guarantor; group guarantees
 * are answered by staff on the group's behalf.
 */
class MemberGuaranteeController extends Controller
{
    public function __construct(
        protected LoanGuarantorServiceInterface $service,
        protected GuarantorArrearsService $arrears,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:requested,accepted,declined,expired,locked,released,invoked'],
        ]);

        $this->service->expireOverdue();

        $guarantees = $this->ownGuarantees($request)
            ->whereIn('status', [
                LoanApplicationGuarantor::STATUS_REQUESTED,
                LoanApplicationGuarantor::STATUS_ACCEPTED,
                LoanApplicationGuarantor::STATUS_DECLINED,
                LoanApplicationGuarantor::STATUS_EXPIRED,
                LoanApplicationGuarantor::STATUS_LOCKED,
                LoanApplicationGuarantor::STATUS_RELEASED,
                LoanApplicationGuarantor::STATUS_INVOKED,
            ])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->with('loanApplication.member')
            ->latest('id')
            ->paginate(15);

        /** @var Member $member */
        $member = $request->user();
        $savings = $this->service->withdrawable('individual', $member->id);

        // How far behind each loan the member guarantees is, so they see it before
        // anyone texts them.
        $overdue = $this->arrears->overdueLoans();

        return response()->json([
            'data' => $guarantees->getCollection()->map(fn ($g) => [
                ...$this->present($g),
                'loan_arrears' => $g->loan_id && isset($overdue[$g->loan_id]) ? [
                    'days_past_due' => $overdue[$g->loan_id]['days_past_due'],
                    'arrears_amount' => $overdue[$g->loan_id]['arrears_amount'],
                    'arrears_amount_formatted' => TenantMoney::format($overdue[$g->loan_id]['arrears_amount']),
                ] : null,
            ]),
            // What the member's guarantees are holding back from withdrawal.
            'savings' => [
                'balance' => $savings['balance'],
                'held' => $savings['held'],
                'held_formatted' => TenantMoney::format($savings['held']),
                'available' => $savings['available'],
                'available_formatted' => TenantMoney::format($savings['available']),
            ],
            'meta' => [
                'current_page' => $guarantees->currentPage(),
                'last_page' => $guarantees->lastPage(),
                'total' => $guarantees->total(),
            ],
        ]);
    }

    public function accept(Request $request, int $id): JsonResponse
    {
        $guarantee = $this->service->respond(
            pledge: $this->ownGuarantees($request)->findOrFail($id),
            accept: true,
            reason: null,
            channel: LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL,
        );

        return response()->json([
            'message' => 'Thank you. You have accepted this guarantee.',
            'data' => $this->present($guarantee->load('loanApplication.member')),
        ]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $guarantee = $this->service->respond(
            pledge: $this->ownGuarantees($request)->findOrFail($id),
            accept: false,
            reason: $validated['reason'] ?? null,
            channel: LoanApplicationGuarantor::CHANNEL_MEMBER_PORTAL,
        );

        return response()->json([
            'message' => 'You have declined this guarantee.',
            'data' => $this->present($guarantee->load('loanApplication.member')),
        ]);
    }

    /**
     * Recovery loans on both sides: ones the member owes because their guarantors
     * covered their loan, and what borrowers owe the member for loans they covered.
     */
    public function recoveries(Request $request, GuarantorRecoveryService $recoveries): JsonResponse
    {
        /** @var Member $member */
        $member = $request->user();

        $owedByMe = GuarantorRecovery::query()
            ->where('member_id', $member->id)
            ->whereNotNull('recovery_loan_status')
            ->with('loan')
            ->latest('id')
            ->get()
            ->map(fn (GuarantorRecovery $r) => [
                'code' => $r->code,
                'loan_no' => $r->loan?->loan_no,
                'status' => $r->recovery_loan_status,
                'amount' => (float) $r->guarantor_amount,
                'repaid' => (float) $r->recovery_loan_repaid,
                'outstanding' => $r->recoveryLoanOutstanding(),
                'outstanding_formatted' => TenantMoney::format($r->recoveryLoanOutstanding()),
                'schedule' => $recoveries->schedule($r),
            ]);

        $owedToMe = GuarantorRecoveryLine::query()
            ->where('member_id', $member->id)
            ->where('source', GuarantorRecoveryLine::SOURCE_GUARANTOR)
            ->whereHas('recovery', fn ($q) => $q->where('status', GuarantorRecovery::STATUS_EXECUTED))
            ->with('recovery.loan', 'recovery.member')
            ->latest('id')
            ->get()
            ->map(fn (GuarantorRecoveryLine $line) => [
                'code' => $line->recovery->code,
                'loan_no' => $line->recovery->loan?->loan_no,
                'borrower_name' => $line->recovery->member?->name,
                'taken' => (float) $line->amount,
                'repaid' => (float) $line->repaid_amount,
                'owed' => $line->owed(),
                'owed_formatted' => TenantMoney::format($line->owed()),
            ]);

        return response()->json(['data' => ['owed_by_me' => $owedByMe, 'owed_to_me' => $owedToMe]]);
    }

    private function ownGuarantees(Request $request)
    {
        /** @var Member $member */
        $member = $request->user();

        return LoanApplicationGuarantor::query()
            ->where('guarantor_type', LoanApplicationGuarantor::TYPE_INDIVIDUAL)
            ->where('guarantor_id', $member->id);
    }

    private function present(LoanApplicationGuarantor $guarantee): array
    {
        $application = $guarantee->loanApplication;

        return [
            'id' => $guarantee->id,
            'status' => $guarantee->status,
            'guarantee_amount' => (float) $guarantee->guarantee_amount,
            'guarantee_amount_formatted' => TenantMoney::format($guarantee->guarantee_amount),
            'requested_at' => $guarantee->requested_at,
            'consent_expires_at' => $guarantee->consent_expires_at,
            'responded_at' => $guarantee->responded_at,
            'decline_reason' => $guarantee->decline_reason,
            'recovered_amount' => (float) $guarantee->recovered_amount,
            'locked_at' => $guarantee->locked_at,
            'released_at' => $guarantee->released_at,
            'loan_application' => $application ? [
                'application_no' => $application->application_no,
                'applicant_name' => $application->member?->name,
                'requested_amount' => (float) $application->requested_amount,
                'requested_amount_formatted' => TenantMoney::format($application->requested_amount),
                'requested_term' => $application->requested_term,
                'purpose' => $application->purpose,
            ] : null,
        ];
    }
}
