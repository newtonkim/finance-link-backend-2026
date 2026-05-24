<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\AddLoanGuarantorRequest;
use App\Tenant\Http\Resources\LoanApplicationGuarantorResource;
use App\Tenant\Modules\Loans\Contracts\LoanGuarantorServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanGuarantor;
use Illuminate\Http\JsonResponse;

class LoanGuarantorController extends Controller
{
    public function __construct(
        protected LoanGuarantorServiceInterface $service,
    ) {}

    public function index(LoanApplication $loanApplication): JsonResponse
    {
        $guarantors = $loanApplication->guarantors()->with('member')->get();

        return response()->json([
            'data' => LoanApplicationGuarantorResource::collection($guarantors),
            'adequate' => $this->service->validateAdequacy($loanApplication),
        ]);
    }

    public function store(AddLoanGuarantorRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        $guarantor = $this->service->addGuarantor(
            application: $loanApplication,
            memberId: $request->integer('member_id'),
            guaranteeAmount: (float) $request->input('guarantee_amount'),
            notes: $request->input('notes'),
            actorId: auth('tenant')->id() ?? auth()->id() ?? 1,
        );

        $guarantor->load('member');

        return response()->json([
            'message' => 'Guarantor added successfully.',
            'data' => new LoanApplicationGuarantorResource($guarantor),
        ], 201);
    }

    public function destroy(LoanApplication $loanApplication, LoanGuarantor $guarantor): JsonResponse
    {
        // Ensure guarantor belongs to this application
        abort_if($guarantor->loan_application_id !== $loanApplication->id, 404);

        $this->service->removeGuarantor($guarantor);

        return response()->json(['message' => 'Guarantor removed successfully.']);
    }

    public function validate(LoanApplication $loanApplication): JsonResponse
    {
        $adequate = $this->service->validateAdequacy($loanApplication);
        $required = (int) ($loanApplication->loanProduct?->min_guarantors ?? 0);
        $actual = $loanApplication->guarantors()->count();

        return response()->json([
            'data' => [
                'adequate' => $adequate,
                'required' => $required,
                'actual' => $actual,
                'remaining_needed' => max(0, $required - $actual),
            ],
        ]);
    }
}
