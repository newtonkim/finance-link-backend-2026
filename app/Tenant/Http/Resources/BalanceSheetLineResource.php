<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BalanceSheetLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
            'gl_code' => $this['gl_code'],
            'name' => $this['name'],
            'level' => $this['level'],
            'is_postable' => $this['is_postable'],
            'is_computed' => $this['is_computed'],
            'amount' => round($this['amount'], 2),
            'compare_amount' => round($this['compare_amount'], 2),
            'children' => self::collection(collect($this['children']))->resolve($request),
        ];
    }
}
