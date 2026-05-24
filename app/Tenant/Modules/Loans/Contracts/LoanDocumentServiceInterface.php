<?php

namespace App\Tenant\Modules\Loans\Contracts;

use App\Tenant\Modules\Loans\Models\LoanApplication;
use App\Tenant\Modules\Loans\Models\LoanApplicationDocument;
use App\Tenant\Modules\Loans\Models\LoanProduct;
use Illuminate\Http\UploadedFile;

interface LoanDocumentServiceInterface
{
    public function upload(
        LoanApplication $application,
        UploadedFile $file,
        string $documentType,
        ?string $label,
        ?string $notes,
        ?int $actorId
    ): LoanApplicationDocument;

    public function updateLabel(
        LoanApplicationDocument $document,
        string $label,
        ?string $notes,
        ?int $actorId
    ): LoanApplicationDocument;

    public function delete(LoanApplicationDocument $document): void;

    /** Returns array of ['slug', 'label', 'required'] for each type defined on the product. */
    public function listRequired(LoanProduct $product): array;

    /** Returns the missing required document slugs for a given workflow stage. */
    public function missingRequiredForStage(LoanApplication $application, string $stage): array;

    /** Returns missing required document slugs grouped by stage. */
    public function missingRequiredByStage(LoanApplication $application): array;

    /** Keeps review-stage document statuses aligned with the application workflow. */
    public function syncReviewDocumentStatus(LoanApplication $application): void;
}
