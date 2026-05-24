<?php

namespace App\Tenant\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LedgerLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'date'            => $this['date'],
            'entry_no'        => $this['entry_no'],
            'journal_type'    => $this['journal_type'],
            'description'     => $this['description'],
            'debit'           => round($this['debit'],           2),
            'credit'          => round($this['credit'],          2),
            'running_balance' => round($this['running_balance'], 2),
            'entity_type'     => $this['entity_type'],
            'entity_id'       => $this['entity_id'],
        ];
    }
}
