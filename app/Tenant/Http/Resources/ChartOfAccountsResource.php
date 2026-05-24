<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChartOfAccountsResource extends JsonResource
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
            'gl_code' => $this->gl_code,
            'name' => $this->name,
            'account_type' => $this->account_type,
            'account_subtype' => $this->account_subtype,
            'normal_balance' => $this->normal_balance,
            'level' => $this->level,
            'parent_id' => $this->parent_id,
            'is_control' => $this->is_control,
            'is_postable' => $this->is_postable,
            'is_active' => $this->is_active,
            'allow_manual' => $this->allow_manual,
            'ifrs_category' => $this->ifrs_category,
            'sort_order' => $this->sort_order,
        ];
    }
}
