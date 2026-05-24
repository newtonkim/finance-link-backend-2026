<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

abstract class LoanProductBaseResource extends JsonResource
{
    protected function resolveIsInUse(): bool
    {
        return (bool) ($this->is_in_use ?? (($this->loans_count ?? 0) > 0));
    }

    protected function resolveCanEditCoreFields(bool $isInUse): bool
    {
        return $this->can_edit_core_fields ?? ! $isInUse;
    }
}
