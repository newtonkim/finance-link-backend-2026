<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AddLoanGuarantorRequest;
use App\Tenant\Http\Resources\LoanApplicationGuarantorResource;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Data\GuarantorRules;
use App\Tenant\Modules\Loans\Models\Loan;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
use App\Tenant\Modules\Loans\Services\GuarantorArrearsService;
use App\Tenant\Modules\Loans\Services\GuarantorReportService;
use App\Tenant\Support\TenantMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LoanGuarantorController extends Controller
{
    public function __construct(
        protected LoanGuarantorServiceInterface $service,
    ) {}

    public function index(LoanApplication $loanApplication): JsonResponse
    {
        // Declined and expired guarantors stay listed so staff can see why and ask
        // again; removed ones are soft-deleted and drop out.
        $guarantors = $loanApplication->guarantors()
            ->with(['member', 'group'])
            ->oldest('id')
            ->get();

        return response()->json([
            'data' => LoanApplicationGuarantorResource::collection($guarantors),
            'summary' => $this->service->summary($loanApplication),
        ]);
    }

    public function store(AddLoanGuarantorRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        $guarantor = $this->service->addGuarantor(
            application: $loanApplication,
            type: $request->string('guarantor_type')->toString(),
            guarantorId: $request->integer('guarantor_id'),
            accountId: $request->filled('guarantor_account_id') ? $request->integer('guarantor_account_id') : null,
            amount: (float) $request->input('guarantee_amount'),
            note: $request->input('note'),
            actorId: $this->actorId(),
        );

        $guarantor->load(['member', 'group']);

        return response()->json([
            'message' => 'Guarantor saved.',
            'data' => new LoanApplicationGuarantorResource($guarantor),
            'summary' => $this->service->summary($loanApplication),
        ], 201);
    }

    public function destroy(LoanApplication $loanApplication, LoanApplicationGuarantor $guarantor): JsonResponse
    {
        abort_if($guarantor->loan_application_id !== $loanApplication->id, 404);

        $this->service->removeGuarantor($guarantor, $this->actorId());

        return response()->json([
            'message' => 'Guarantor removed.',
            'summary' => $this->service->summary($loanApplication),
        ]);
    }

    /** Send, or send again, the request asking the guarantor to accept or decline. */
    public function requestConsent(LoanApplication $loanApplication, LoanApplicationGuarantor $guarantor): JsonResponse
    {
        abort_if($guarantor->loan_application_id !== $loanApplication->id, 404);

        $guarantor = $this->service->requestConsent($guarantor, $this->actorId());
        $guarantor->load(['member', 'group']);

        return response()->json([
            'message' => 'Request sent to the guarantor.',
            'data' => new LoanApplicationGuarantorResource($guarantor),
            'summary' => $this->service->summary($loanApplication),
        ]);
    }

    /**
     * Record a guarantor's answer on their behalf, typically from a signed form,
     * which can be attached.
     */
    public function recordConsent(Request $request, LoanApplication $loanApplication, LoanApplicationGuarantor $guarantor): JsonResponse
    {
        abort_if($guarantor->loan_application_id !== $loanApplication->id, 404);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,declined'],
            'reason' => ['nullable', 'required_if:decision,declined', 'string', 'max:500'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $documentPath = $request->hasFile('document')
            ? $request->file('document')->store("loan-documents/{$loanApplication->id}/guarantor-consent", 'public')
            : null;

        try {
            $guarantor = $this->service->respond(
                pledge: $guarantor,
                accept: $validated['decision'] === 'accepted',
                reason: $validated['reason'] ?? null,
                channel: LoanApplicationGuarantor::CHANNEL_OFFICER,
                staffId: $this->actorId(),
                documentPath: $documentPath,
            );
        } catch (\Throwable $e) {
            if ($documentPath) {
                Storage::disk('public')->delete($documentPath);
            }

            throw $e;
        }
        $guarantor->load(['member', 'group']);

        return response()->json([
            'message' => $guarantor->status === LoanApplicationGuarantor::STATUS_ACCEPTED
                ? 'Guarantee recorded as accepted.'
                : 'Guarantee recorded as declined.',
            'data' => new LoanApplicationGuarantorResource($guarantor),
            'summary' => $this->service->summary($loanApplication),
            'application_status' => $loanApplication->fresh()->status,
        ]);
    }

    public function summary(LoanApplication $loanApplication): JsonResponse
    {
        return response()->json(['data' => $this->service->summary($loanApplication)]);
    }

    /**
     * A member's or group's standing as a guarantor: how much more they can pledge,
     * how much of their savings is held, and the guarantees that hold it.
     */
    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guarantor_type' => ['required', 'string', 'in:individual,group'],
            'guarantor_id' => ['required', 'integer', 'min:1'],
        ]);

        $type = $validated['guarantor_type'];
        $id = (int) $validated['guarantor_id'];
        $free = $this->service->freeCapacity($type, $id);
        $withdrawable = $this->service->withdrawable($type, $id);

        $guarantees = LoanApplicationGuarantor::query()
            ->where('guarantor_type', $type)
            ->where('guarantor_id', $id)
            ->whereIn('status', LoanApplicationGuarantor::ACTIVE_STATUSES)
            ->with(['loanApplication.member', 'loan'])
            ->latest('id')
            ->get()
            ->map(fn (LoanApplicationGuarantor $g) => [
                'id' => $g->id,
                'status' => $g->status,
                'guarantee_amount' => (float) $g->guarantee_amount,
                'guarantee_amount_formatted' => TenantMoney::format($g->guarantee_amount),
                'loan_application_id' => $g->loan_application_id,
                'application_no' => $g->loanApplication?->application_no,
                'borrower_name' => $g->loanApplication?->member?->name,
                'loan_id' => $g->loan_id,
                'loan_no' => $g->loan?->loan_no,
                'locked_at' => $g->locked_at,
            ]);

        return response()->json(['data' => [
            'guarantor_type' => $type,
            'guarantor_id' => $id,
            'free_capacity' => $free,
            'free_capacity_formatted' => TenantMoney::format($free),
            'savings_balance' => $withdrawable['balance'],
            'held_amount' => $withdrawable['held'],
            'held_amount_formatted' => TenantMoney::format($withdrawable['held']),
            'available_to_withdraw' => $withdrawable['available'],
            'available_to_withdraw_formatted' => TenantMoney::format($withdrawable['available']),
            'guarantees' => $guarantees,
        ]]);
    }

    /** Overdue loans with guarantees standing behind them, most overdue first. */
    public function arrearsWatchList(GuarantorArrearsService $arrears): JsonResponse
    {
        return response()->json([
            'data' => $arrears->watchList(),
            'rules' => GuarantorRules::for()->toArray(),
        ]);
    }

    /** Warn an overdue loan's guarantors now, without waiting for the daily run. */
    public function notifyArrears(GuarantorArrearsService $arrears, Loan $loan): JsonResponse
    {
        $count = $arrears->notifyLoan($loan);

        return response()->json([
            'message' => "Warned {$count} guarantor(s).",
            'data' => ['notified' => $count],
        ]);
    }

    /**
     * Replace a guarantor on a running loan. With consent required the replacement
     * is asked first, and the old guarantor stays until they accept.
     */
    public function substitute(Request $request, LoanApplicationGuarantor $guarantor): JsonResponse
    {
        $validated = $request->validate([
            'guarantor_type' => ['required', 'string', 'in:individual,group'],
            'guarantor_id' => ['required', 'integer', 'min:1'],
            'guarantor_account_id' => ['nullable', 'integer', 'min:1'],
            'guarantee_amount' => ['nullable', 'numeric', 'gt:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $replacement = $this->service->substitute(
            old: $guarantor,
            type: $validated['guarantor_type'],
            guarantorId: (int) $validated['guarantor_id'],
            accountId: isset($validated['guarantor_account_id']) ? (int) $validated['guarantor_account_id'] : null,
            amount: isset($validated['guarantee_amount']) ? (float) $validated['guarantee_amount'] : null,
            note: $validated['note'] ?? null,
            actorId: $this->actorId(),
        );
        $replacement->load(['member', 'group']);

        return response()->json([
            'message' => $replacement->status === LoanApplicationGuarantor::STATUS_LOCKED
                ? 'Guarantor replaced.'
                : 'The replacement has been asked to accept. The current guarantor stays until they do.',
            'data' => new LoanApplicationGuarantorResource($replacement),
        ], 201);
    }

    /** Guarantor reports: exposure, pending_consents or release_requests. */
    public function report(Request $request, GuarantorReportService $reports): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['required', 'string', 'in:exposure,pending_consents,release_requests'],
        ]);

        return response()->json(['data' => match ($validated['view']) {
            'exposure' => $reports->exposure(),
            'pending_consents' => $reports->pendingConsents(),
            'release_requests' => $reports->releaseRequests(),
        }]);
    }

    private function actorId(): ?int
    {
        return auth('tenant')->id() ?? auth()->id();
    }
}
