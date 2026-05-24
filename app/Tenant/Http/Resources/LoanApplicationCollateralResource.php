<?php

namespace App\Tenant\Http\Resources;

use App\Tenant\Support\TenantMoney;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class LoanApplicationCollateralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_type' => $this->asset_type,
            'description' => $this->description,
            'estimated_value' => $this->estimated_value,
            'estimated_value_formatted' => TenantMoney::format($this->estimated_value),
            'notes' => $this->notes,
            'proof_url' => $this->proof_path
                ? Storage::disk('public')->url($this->proof_path)
                : null,
            'created_at' => $this->created_at,
        ];
    }
}
