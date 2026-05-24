<?php

namespace App\Tenant\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\UploadLoanDocumentRequest;
use App\Tenant\Http\Resources\LoanApplicationDocumentResource;
use App\Tenant\Modules\Loans\Contracts\LoanDocumentServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanDocumentController extends Controller
{
    public function __construct(
        protected LoanDocumentServiceInterface $service,
    ) {}

    public function index(LoanApplication $loanApplication): JsonResponse
    {
        $loanApplication->loadMissing('loanProduct');

        $documents = $loanApplication->documents()->orderBy('created_at')->get();
        $required = $loanApplication->loanProduct
            ? $this->service->listRequired($loanApplication->loanProduct)
            : [];
        $missingByStage = $this->service->missingRequiredByStage($loanApplication);

        // Annotate required types with upload status
        $uploadedTypes = $documents->pluck('document_type')->unique()->values();
        $required = array_map(function (array $req) use ($uploadedTypes) {
            $req['uploaded'] = $uploadedTypes->contains($req['slug']);

            return $req;
        }, $required);

        return response()->json([
            'data' => LoanApplicationDocumentResource::collection($documents),
            'required' => $required,
            'missing_by_stage' => $missingByStage,
        ]);
    }

    public function store(UploadLoanDocumentRequest $request, LoanApplication $loanApplication): JsonResponse
    {
        $document = $this->service->upload(
            application: $loanApplication,
            file: $request->file('file'),
            documentType: $request->input('document_type'),
            label: $request->input('name'),
            notes: $request->input('notes'),
            actorId: auth('tenant')->id() ?? auth()->id() ?? 1,
        );

        return response()->json([
            'message' => 'Document uploaded successfully.',
            'data' => new LoanApplicationDocumentResource($document),
        ], 201);
    }

    public function update(Request $request, LoanApplication $loanApplication, LoanApplicationDocument $document): JsonResponse
    {
        abort_if($document->loan_application_id !== $loanApplication->id, 404);

        $request->validate(['name' => 'required|string|max:255']);

        $updated = $this->service->updateLabel(
            document: $document,
            label: $request->input('name'),
            notes: $request->input('notes'),
            actorId: auth('tenant')->id() ?? auth()->id() ?? 1,
        );

        return response()->json([
            'message' => 'Document updated successfully.',
            'data' => new LoanApplicationDocumentResource($updated),
        ]);
    }

    public function destroy(LoanApplication $loanApplication, LoanApplicationDocument $document): JsonResponse
    {
        abort_if($document->loan_application_id !== $loanApplication->id, 404);

        $this->service->delete($document);

        return response()->json(['message' => 'Document deleted successfully.']);
    }

    public function download(LoanApplication $loanApplication, LoanApplicationDocument $document): StreamedResponse
    {
        abort_if($document->loan_application_id !== $loanApplication->id, 404);
        abort_unless(Storage::disk('public')->exists($document->file_path), 404);

        return Storage::disk('public')->download($document->file_path, $document->original_name);
    }
}
