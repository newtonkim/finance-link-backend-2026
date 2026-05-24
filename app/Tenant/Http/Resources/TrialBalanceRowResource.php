<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrialBalanceRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this['id'],
            'gl_code'         => $this['gl_code'],
            'name'            => $this['name'],
            'account_type'    => $this['account_type'],
            'account_subtype' => $this['account_subtype'],
            'level'           => $this['level'],
            'is_postable'     => $this['is_postable'],
            'opening_debit'   => round($this['opening_debit'],  2),
            'opening_credit'  => round($this['opening_credit'], 2),
            'period_debit'    => round($this['period_debit'],   2),
            'period_credit'   => round($this['period_credit'],  2),
            'closing_debit'   => round($this['closing_debit'],  2),
            'closing_credit'  => round($this['closing_credit'], 2),
        ];
    }
}
