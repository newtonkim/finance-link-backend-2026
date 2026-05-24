<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ExpenseAttachmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'file_name' => $this->file_name,
            'file_url' => Storage::disk('public')->url($this->file_path),
            'file_type' => $this->file_type,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
        ];
    }
}
