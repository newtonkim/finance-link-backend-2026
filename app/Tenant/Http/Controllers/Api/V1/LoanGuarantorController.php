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

class LoanGuarantorController extends Controller
{
    public function __construct(
        protected LoanGuarantorServiceInterface $service,
    ) {}

    public function index(LoanApplication $loanApplication): JsonResponse
    {
        $guarantors = $loanApplication->guarantors()
            ->active()
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
