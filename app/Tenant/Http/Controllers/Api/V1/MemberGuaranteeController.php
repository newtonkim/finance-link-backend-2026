<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', 'in:requested,accepted,declined,expired'],
        ]);

        $this->service->expireOverdue();

        $guarantees = $this->ownGuarantees($request)
            ->whereIn('status', [
                LoanApplicationGuarantor::STATUS_REQUESTED,
                LoanApplicationGuarantor::STATUS_ACCEPTED,
                LoanApplicationGuarantor::STATUS_DECLINED,
                LoanApplicationGuarantor::STATUS_EXPIRED,
            ])
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->with('loanApplication.member')
            ->latest('requested_at')
            ->paginate(15);

        return response()->json([
            'data' => $guarantees->getCollection()->map(fn ($g) => $this->present($g)),
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
