<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Tenant\Modules\Loans\Contracts\ScheduleGeneratorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApprovalSetting;
use App\Tenant\Modules\Loans\Models\LoanApprovalVote;
use App\Tenant\Modules\Loans\Services\LoanApplicationStatusGuard;
use App\Tenant\Modules\Loans\Services\LoanPermissionsService;
use App\Tenant\Modules\Loans\Services\LoanQuorumEvaluator;
use App\Tenant\Modules\Settings\Models\SaccoBranding;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LoanCommitteeController extends Controller
{
    public function __construct(
        protected LoanApplicationStatusGuard $statusGuard,
        protected LoanQuorumEvaluator $quorumEvaluator,
        protected LoanPermissionsService $permissions,
        protected ScheduleGeneratorServiceInterface $scheduleGenerator,
    ) {}

    /**
     * BM recommends the application (officer_recommended → bm_recommended → committee_voting).
     */
    public function bmRecommend(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $this->assertStatus($loanApplication, LoanApplication::STATUS_OFFICER_RECOMMENDED, 'bm-recommend');

        $staff = $this->currentStaff();
        $isAdmin = $staff->is_tenant_admin || strtolower($staff->role) === 'admin';
        if (! $staff->can_manage_branch && ! $isAdmin) {
            throw ValidationException::withMessages([
                'permission' => ['Only branch managers are allowed to recommend applications.'],
            ]);
        }

        $validated = $request->validate([
            'bm_notes' => 'nullable|string',
        ]);

        $loanApplication->update([
            'bm_notes' => $validated['bm_notes'] ?? null,
        ]);

        // Transition to bm_recommended
        $this->statusGuard->transition(
            $loanApplication,
            LoanApplication::STATUS_BM_RECOMMENDED,
            'Branch Manager recommended the application.'
        );

        // Immediately transition to committee_voting
        $loanApplication->refresh();

        // Resolve quorum settings from loan product
        $quorumSettings = $this->resolveQuorumSettings($loanApplication);

        $loanApplication->update([
            'quorum_required' => $quorumSettings['quorum_size'],
            'approval_threshold' => $quorumSettings['approval_threshold'],
            'unanimity_required' => $quorumSettings['unanimity_required'],
        ]);

        $this->statusGuard->transition(
            $loanApplication,
            LoanApplication::STATUS_COMMITTEE_VOTING,
            'Committee voting opened.'
        );

        $loanApplication->refresh();

        return response()->json([
            'message' => 'Application recommended and committee voting opened.',
            'data' => [
                'application' => $loanApplication->fresh(),
                'permitted_actions' => $this->permissions->forUser($loanApplication, $staff),
            ],
        ]);
    }

    /**
     * BM returns the application for correction (officer_recommended → returned_for_correction).
     */
    public function returnForCorrection(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $this->assertStatus($loanApplication, LoanApplication::STATUS_OFFICER_RECOMMENDED, 'return-for-correction');

        $staff = $this->currentStaff();
        $isAdmin = $staff->is_tenant_admin || strtolower($staff->role) === 'admin';
        if (! $staff->can_manage_branch && ! $isAdmin) {
            throw ValidationException::withMessages([
                'permission' => ['Only branch managers can return applications for correction.'],
            ]);
        }

        $validated = $request->validate([
            'correction_reason' => 'required|string',
        ]);

        $loanApplication->update([
            'correction_reason' => $validated['correction_reason'],
        ]);

        $this->statusGuard->transition(
            $loanApplication,
            LoanApplication::STATUS_RETURNED_FOR_CORRECTION,
            $validated['correction_reason']
        );

        $loanApplication->refresh();

        return response()->json([
            'message' => 'Application returned for correction.',
            'data' => [
                'application' => $loanApplication,
                'permitted_actions' => $this->permissions->forUser($loanApplication, $staff),
            ],
        ]);
    }

    /**
     * Cast a vote on a loan application in committee voting.
     */
    public function castVote(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $this->assertStatus($loanApplication, LoanApplication::STATUS_COMMITTEE_VOTING, 'cast-vote');

        $staff = $this->currentStaff();
        $isAdmin = $staff->is_tenant_admin || strtolower($staff->role) === 'admin';
        if (! $staff->can_vote_on_loans && ! $isAdmin) {
            throw ValidationException::withMessages([
                'permission' => ['You do not have permission to vote on loan applications.'],
            ]);
        }

        // Check if already voted
        $existingVote = LoanApprovalVote::where('loan_application_id', $loanApplication->id)
            ->where('staff_id', $staff->id)
            ->first();

        if ($existingVote) {
            throw ValidationException::withMessages([
                'vote' => ['You have already cast a vote on this application.'],
            ]);
        }

        $validated = $request->validate([
            'decision' => 'required|in:approve,decline',
            'comment' => 'nullable|string',
        ]);

        LoanApprovalVote::create([
            'loan_application_id' => $loanApplication->id,
            'staff_id' => $staff->id,
            'decision' => $validated['decision'],
            'comment' => $validated['comment'] ?? null,
            'abstained' => false,
        ]);

        // Evaluate quorum
        $this->quorumEvaluator->evaluate($loanApplication);

        $loanApplication->refresh();

        return response()->json([
            'message' => 'Vote cast successfully.',
            'data' => [
                'application' => $loanApplication,
                'permitted_actions' => $this->permissions->forUser($loanApplication, $staff),
                'vote_tally' => $this->getVoteTally($loanApplication, $staff),
            ],
        ]);
    }

    /**
     * Get votes for a loan application.
     */
    public function getVotes(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $staff = $this->currentStaff();
        $tally = $this->getVoteTally($loanApplication, $staff);

        return response()->json([
            'data' => $tally,
        ]);
    }

    /**
     * BM marks a vote as abstained.
     */
    public function markAbstention(Request $request, LoanApplication $loanApplication, int $staffId): JsonResponse
    {
        $this->assertStatus($loanApplication, LoanApplication::STATUS_COMMITTEE_VOTING, 'mark-abstention');

        $currentStaff = $this->currentStaff();
        $isAdmin = $currentStaff->is_tenant_admin || strtolower($currentStaff->role) === 'admin';
        if (! $currentStaff->can_manage_branch && ! $isAdmin) {
            throw ValidationException::withMessages([
                'permission' => ['Only branch managers can mark abstentions.'],
            ]);
        }

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $vote = LoanApprovalVote::where('loan_application_id', $loanApplication->id)
            ->where('staff_id', $staffId)
            ->first();

        if (! $vote) {
            throw ValidationException::withMessages([
                'vote' => ['No vote found for this staff member.'],
            ]);
        }

        $vote->update([
            'abstained' => true,
            'abstained_by' => $currentStaff->id,
            'comment' => $validated['reason'],
        ]);

        // Re-evaluate quorum
        $this->quorumEvaluator->evaluate($loanApplication);

        $loanApplication->refresh();

        return response()->json([
            'message' => 'Abstention marked successfully.',
            'data' => [
                'application' => $loanApplication,
                'permitted_actions' => $this->permissions->forUser($loanApplication, $currentStaff),
            ],
        ]);
    }

    /**
     * Confirm terms and lock schedule (approved → disbursement_pending).
     */
    public function confirmTerms(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $this->assertStatus($loanApplication, LoanApplication::STATUS_APPROVED, 'confirm-terms');

        $staff = $this->currentStaff();
        $isAdmin = $staff->is_tenant_admin || strtolower($staff->role) === 'admin';
        if (! $staff->can_finalise_loan && ! $isAdmin) {
            throw ValidationException::withMessages([
                'permission' => ['You do not have permission to confirm loan terms.'],
            ]);
        }

        $validated = $request->validate([
            'final_approved_amount' => 'required|numeric|min:0',
            'final_approved_term' => 'required|integer|min:1',
            'approved_interest_rate' => 'nullable|numeric|min:0',
            'proposed_start_date' => 'required|date',
        ]);

        $loanApplication->update([
            'final_approved_amount' => $validated['final_approved_amount'],
            'final_approved_term' => $validated['final_approved_term'],
            'approved_interest_rate' => $validated['approved_interest_rate'] ?? $loanApplication->recommended_interest_rate,
            'proposed_start_date' => $validated['proposed_start_date'],
            'schedule_locked_at' => now(),
        ]);

        $this->statusGuard->transition(
            $loanApplication,
            LoanApplication::STATUS_DISBURSEMENT_PENDING,
            'Terms confirmed and schedule locked.'
        );

        $loanApplication->refresh();

        return response()->json([
            'message' => 'Terms confirmed and schedule locked.',
            'data' => [
                'application' => $loanApplication,
                'permitted_actions' => $this->permissions->forUser($loanApplication, $staff),
            ],
        ]);
    }

    /**
     * Get proposed repayment schedule.
     */
    public function proposedSchedule(Request $request, LoanApplication $loanApplication): JsonResponse
    {
        $product = $loanApplication->loanProduct;

        $principal = (float) ($request->query('amount', $loanApplication->final_approved_amount ?? $loanApplication->recommended_amount ?? $loanApplication->requested_amount));
        $termMonths = (int) ($request->query('term', $loanApplication->final_approved_term ?? $loanApplication->recommended_term ?? $loanApplication->requested_term));
        $interestRate = (float) ($request->query('interest_rate', $loanApplication->approved_interest_rate ?? $loanApplication->recommended_interest_rate ?? $product->interest_rate ?? 0));
        $startDate = Carbon::parse($request->query('start_date', now()->format('Y-m-d')));

        $graceDays = (int) ($product->grace_period ?? 0);
        $baseDate = $startDate->copy()->addDays($graceDays);

        $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');

        $result = $this->scheduleGenerator->generate(
            $principal,
            $termMonths,
            (string) ($product->interest_method ?? 'flat'),
            (string) ($product->repayment_structure ?? 'equal_installment'),
            $interestRate,
            (string) ($product->interest_period ?? 'monthly'),
            $repaymentCycle,
        );

        $graceDays = (int) ($product->grace_period ?? 0);
        $baseDate = $startDate->copy()->addDays($graceDays);

        $installments = [];
        foreach ($result['rows'] as $row) {
            // If grace period is present, row 1 should start AT the baseDate (start + grace).
            // addRepaymentCycle adds (offset * cycle) to the baseDate.
            $periodOffset = $row['period'] - 1;
            $dueDate = $this->addRepaymentCycle($baseDate->copy(), $repaymentCycle, (int) $periodOffset);

            $installments[] = [
                'number' => $row['period'],
                'due_date' => $dueDate->format('Y-m-d'),
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'total' => $row['installment'],
                'balance' => $row['balance'],
            ];
        }

        return response()->json([
            'data' => [
                'amount' => $principal,
                'term' => $termMonths,
                'interest_method' => $product->interest_method ?? 'flat',
                'interest_period' => $product->interest_period ?? 'monthly',
                'grace_days' => $graceDays,
                'repayment_cycle' => $repaymentCycle,
                'installments' => $installments,
                'summary' => [
                    'monthly_installment' => $result['installment_amount'],
                    'total_principal' => $principal,
                    'total_interest' => $result['total_interest'],
                    'total_repayment' => round($principal + $result['total_interest'], 2),
                ],
            ],
        ]);
    }

    /**
     * Export proposed repayment schedule as PDF.
     */
    public function exportProposedSchedule(Request $request, LoanApplication $loanApplication)
    {
        $product = $loanApplication->loanProduct;

        $principal = (float) ($request->query('amount', $loanApplication->final_approved_amount ?? $loanApplication->recommended_amount ?? $loanApplication->requested_amount));
        $termMonths = (int) ($request->query('term', $loanApplication->final_approved_term ?? $loanApplication->recommended_term ?? $loanApplication->requested_term));
        $interestRate = (float) ($request->query('interest_rate', $loanApplication->approved_interest_rate ?? $loanApplication->recommended_interest_rate ?? $product->interest_rate ?? 0));
        $startDate = Carbon::parse($request->query('start_date', now()->format('Y-m-d')));

        $graceDays = (int) ($product->grace_period ?? 0);
        $baseDate = $startDate->copy()->addDays($graceDays);

        $repaymentCycle = (string) ($product->repayment_cycle ?? 'monthly');

        $result = $this->scheduleGenerator->generate(
            $principal,
            $termMonths,
            (string) ($product->interest_method ?? 'flat'),
            (string) ($product->repayment_structure ?? 'equal_installment'),
            $interestRate,
            (string) ($product->interest_period ?? 'monthly'),
            $repaymentCycle,
        );

        $installments = [];
        foreach ($result['rows'] as $row) {
            $periodOffset = $row['period'] - 1;
            $dueDate = $this->addRepaymentCycle($baseDate->copy(), $repaymentCycle, (int) $periodOffset);

            $installments[] = [
                'number' => $row['period'],
                'due_date' => $dueDate->format('Y-m-d'),
                'principal' => $row['principal'],
                'interest' => $row['interest'],
                'total' => $row['installment'],
                'balance' => $row['balance'],
            ];
        }

        $summary = [
            'monthly_installment' => $result['installment_amount'],
            'total_principal' => $principal,
            'total_interest' => $result['total_interest'],
            'total_repayment' => round($principal + $result['total_interest'], 2),
        ];

        $branding = SaccoBranding::current();
        $logoDataUri = null;
        if ($branding->logo_path) {
            $logoAbsPath = Storage::disk('public')->path($branding->logo_path);
            if (file_exists($logoAbsPath)) {
                $ext = strtolower(pathinfo($logoAbsPath, PATHINFO_EXTENSION));
                $mime = match ($ext) {
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    default => 'image/jpeg',
                };
                $logoDataUri = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logoAbsPath));
            }
        }

        $data = [
            'application' => $loanApplication,
            'product' => $product,
            'member' => $loanApplication->member,
            'principal' => $principal,
            'termMonths' => $termMonths,
            'startDate' => $startDate->format('Y-m-d'),
            'installments' => $installments,
            'summary' => $summary,
            'repaymentCycle' => $repaymentCycle,
            'saccoName' => $branding->sacco_name ?? 'Your Company Name',
            'saccoTagline' => $branding->tagline,
            'logoDataUri' => $logoDataUri,
        ];

        $pdf = Pdf::loadView('pdf.loan-proposed-schedule', $data);

        $filename = 'proposed_schedule_'.($loanApplication->member->name ?? 'loan').'_'.now()->format('YmdHis').'.pdf';

        if ($request->has('print')) {
            return $pdf->stream($filename);
        }

        return $pdf->download($filename);
    }

    private function addRepaymentCycle(Carbon $base, string $cycle, int $period): Carbon
    {
        return match (strtolower($cycle)) {
            'daily' => $base->addDays($period),
            'weekly' => $base->addWeeks($period),
            'bi-weekly', 'biweekly' => $base->addWeeks($period * 2),
            'bi-monthly', 'bimonthly' => $base->addMonths($period * 2),
            'quarterly' => $base->addMonths($period * 3),
            'annual', 'yearly', 'annually' => $base->addYears($period),
            default => $base->addMonths($period),
        };
    }

    /**
     * Assert the application is in the expected status.
     */
    private function assertStatus(LoanApplication $application, string $expected, string $action): void
    {
        if ($application->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot {$action}: application must be in '{$expected}' status (current: '{$application->status}').",
                ],
            ]);
        }
    }

    /**
     * Get the current authenticated staff member.
     */
    private function currentStaff(): Staff
    {
        $userId = auth()->id() ?? auth('tenant')->id();

        return Staff::findOrFail($userId);
    }

    /**
     * Resolve quorum settings for a loan application.
     */
    private function resolveQuorumSettings(LoanApplication $application): array
    {
        $setting = LoanApprovalSetting::where(
            'loan_product_id',
            $application->loan_product_id
        )->first();

        if ($setting) {
            return $setting->resolveForAmount((float) ($application->recommended_amount ?? $application->requested_amount));
        }

        return [
            'quorum_size' => 1,
            'approval_threshold' => 1,
            'unanimity_required' => false,
        ];
    }

    /**
     * Get vote tally for a loan application.
     */
    private function getVoteTally(LoanApplication $application, Staff $staff): array
    {
        $votes = LoanApprovalVote::with('staff')
            ->where('loan_application_id', $application->id)
            ->get();

        $total = $votes->count();
        $yes = $votes->where('decision', 'approve')->where('abstained', false)->count();
        $no = $votes->where('decision', 'decline')->where('abstained', false)->count();
        $abstained = $votes->where('abstained', true)->count();

        $hasVoted = $votes->contains('staff_id', $staff->id);
        $canSeeDetails = $hasVoted || $staff->can_manage_branch;

        $result = [
            'total_cast' => $total,
            'quorum_required' => $application->quorum_required,
            'approval_threshold' => $application->approval_threshold,
            'outcome_counts' => [
                'approve' => $yes,
                'decline' => $no,
                'abstained' => $abstained,
            ],
        ];

        if ($canSeeDetails) {
            $result['votes'] = $votes->map(function ($vote) {
                return [
                    'staff_id' => $vote->staff_id,
                    'staff_name' => $vote->staff->name ?? 'Unknown',
                    'decision' => $vote->decision,
                    'comment' => $vote->comment,
                    'abstained' => $vote->abstained,
                    'created_at' => $vote->created_at,
                ];
            });
        }

        // Always include the full committee member list with voted status
        $result['has_voted'] = $hasVoted;
        $result['committee_members'] = Staff::where('can_vote_on_loans', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function ($member) use ($votes) {
                $vote = $votes->firstWhere('staff_id', $member->id);

                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'has_voted' => (bool) $vote,
                    'decision' => $vote?->decision,
                    'abstained' => (bool) ($vote?->abstained ?? false),
                ];
            })->values();

        return $result;
    }
}
