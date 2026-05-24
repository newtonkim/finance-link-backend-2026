<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Modules\Loans\Data\DocumentType as LegacyDocumentType;
use App\Tenant\Modules\Loans\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanApplicationDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $typeLabel = null;
        if (! $this->document_label) {
            $documentType = DocumentType::query()
                ->where('code', $this->document_type)
                ->first(['code', 'name']);
            $typeLabel = $documentType?->name ?? LegacyDocumentType::label($this->document_type);
        }

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_label' => $this->document_label ?? $typeLabel,
            'original_name' => $this->original_name,
            'url' => $this->url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_at' => $this->created_at,
        ];
    }
}
