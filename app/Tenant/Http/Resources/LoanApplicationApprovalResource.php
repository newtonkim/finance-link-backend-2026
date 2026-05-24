<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoanApplicationApprovalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'approver' => $this->whenLoaded('approver', fn () => [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ]),
            'level' => $this->level,
            'decision' => $this->decision,
            'comments' => $this->comments,
            'decided_at' => $this->decided_at,
            'created_at' => $this->created_at,
        ];
    }
}
