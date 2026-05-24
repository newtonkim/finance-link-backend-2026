<?php

namespace App\Tenant\Modules\Loans\Services;

use App\Tenant\Modules\Loans\Contracts\LoanDocumentServiceInterface;
use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationDocument;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class LoanDocumentService implements LoanDocumentServiceInterface
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly LoanApplicationStatusGuard $statusGuard,
    ) {}

    public function upload(
        LoanApplication $application,
        UploadedFile $file,
        string $documentType,
        ?string $label,
        ?string $notes,
        ?int $actorId
    ): LoanApplicationDocument {
        $this->guardApplicationEditable($application);

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages([
                'file' => ['File must not exceed 10 MB.'],
            ]);
        }

        $path = $file->store(
            "loan-documents/{$application->id}",
            'public'
        );

        $document = LoanApplicationDocument::create([
            'loan_application_id' => $application->id,
            'document_type' => $documentType,
            'document_label' => $label ?: null,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => LoanApplicationDocument::STATUS_PENDING,
            'notes' => $notes,
            'created_by' => $actorId,
        ]);

        $this->syncReviewDocumentStatus($application->fresh(['loanProduct', 'documents']));

        return $document;
    }

    public function updateLabel(
        LoanApplicationDocument $document,
        string $label,
        ?string $notes,
        ?int $actorId
    ): LoanApplicationDocument {
        $this->guardApplicationEditable($document->loanApplication);

        $document->update([
            'document_label' => $label ?: null,
            'notes' => $notes,
            'updated_by' => $actorId,
        ]);

        return $document->fresh();
    }

    public function delete(LoanApplicationDocument $document): void
    {
        $this->guardApplicationEditable($document->loanApplication);

        Storage::disk('public')->delete($document->file_path);
        $document->delete();

        $this->syncReviewDocumentStatus($document->loanApplication->fresh(['loanProduct', 'documents']));
    }

    public function listRequired(LoanProduct $product): array
    {
        $product->loadMissing('requiredDocuments.documentType');

        return $product->requiredDocuments
            ->filter(fn ($row) => $row->is_active && $row->is_required && $row->documentType)
            ->map(fn ($row) => [
                'slug' => $row->documentType->code,
                'label' => $row->documentType->name,
                'required' => true,
                'stage' => $row->required_stage,
            ])
            ->values()
            ->all();
    }

    public function missingRequiredForStage(LoanApplication $application, string $stage): array
    {
        $application->loadMissing(['loanProduct', 'documents']);

        if (! $application->loanProduct) {
            return [];
        }

        $requiredSlugs = collect($this->listRequired($application->loanProduct))
            ->filter(fn (array $row) => ($row['stage'] ?? 'submission') === $stage && ($row['required'] ?? false))
            ->pluck('slug')
            ->unique()
            ->values();

        if ($requiredSlugs->isEmpty()) {
            return [];
        }

        $uploadedTypes = $application->documents
            ->pluck('document_type')
            ->unique();

        return $requiredSlugs
            ->reject(fn (string $slug) => $uploadedTypes->contains($slug))
            ->values()
            ->all();
    }

    public function missingRequiredByStage(LoanApplication $application): array
    {
        $application->loadMissing(['loanProduct', 'documents']);

        return collect(['submission', 'review', 'approval', 'disbursement'])
            ->mapWithKeys(fn (string $stage) => [$stage => $this->missingRequiredForStage($application, $stage)])
            ->all();
    }

    public function syncReviewDocumentStatus(LoanApplication $application): void
    {
        $application->loadMissing(['loanProduct', 'documents']);

        if (! in_array($application->status, [
            LoanApplication::STATUS_UNDER_REVIEW,
            LoanApplication::STATUS_AWAITING_DOCUMENTS,
        ], true)) {
            return;
        }

        $missingReviewDocuments = $this->missingRequiredForStage($application, 'review');

        if (! empty($missingReviewDocuments)
            && $application->status === LoanApplication::STATUS_UNDER_REVIEW
            && $this->statusGuard->canTransition($application, LoanApplication::STATUS_AWAITING_DOCUMENTS)
        ) {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_AWAITING_DOCUMENTS,
                'Review-stage documents are still missing.'
            );

            return;
        }

        if (empty($missingReviewDocuments)
            && $application->status === LoanApplication::STATUS_AWAITING_DOCUMENTS
            && $this->statusGuard->canTransition($application, LoanApplication::STATUS_UNDER_REVIEW)
        ) {
            $this->statusGuard->transition(
                $application,
                LoanApplication::STATUS_UNDER_REVIEW,
                'All review-stage documents have been uploaded.'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function guardApplicationEditable(LoanApplication $application): void
    {
        if (! in_array($application->status, ['draft', 'submitted', 'under_review', 'awaiting_documents'], true)) {
            throw ValidationException::withMessages([
                'application' => ['Documents cannot be modified at this stage.'],
            ]);
        }
    }
}
