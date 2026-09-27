<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AddLoanGuarantorRequest;
use App\Tenant\Http\Resources\LoanApplicationGuarantorResource;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationGuarantor;
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

    /** How much more a member or group can pledge, for showing while picking guarantors. */
    public function capacity(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'guarantor_type' => ['required', 'string', 'in:individual,group'],
            'guarantor_id' => ['required', 'integer', 'min:1'],
        ]);

        $free = $this->service->freeCapacity($validated['guarantor_type'], (int) $validated['guarantor_id']);

        return response()->json(['data' => [
            'guarantor_type' => $validated['guarantor_type'],
            'guarantor_id' => (int) $validated['guarantor_id'],
            'free_capacity' => $free,
            'free_capacity_formatted' => TenantMoney::format($free),
        ]]);
    }

    private function actorId(): ?int
    {
        return auth('tenant')->id() ?? auth()->id();
    }
}
