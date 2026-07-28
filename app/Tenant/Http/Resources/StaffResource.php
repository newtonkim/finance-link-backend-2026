<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_id' => $this->role_id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,
            'is_tenant_admin' => (bool) $this->is_tenant_admin,
            'is_loan_officer' => (bool) $this->is_loan_officer,
            'can_vote_on_loans' => (bool) $this->can_vote_on_loans,
            'can_manage_branch' => (bool) $this->can_manage_branch,
            'can_finalise_loan' => (bool) $this->can_finalise_loan,
            'avatar' => $this->avatar_url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
