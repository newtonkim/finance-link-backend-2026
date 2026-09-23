<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class GroupSavingsExport implements FromCollection
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection(): Collection
    {
        return collect($this->data)->map(function ($row, $index) {
            return [
                '#' => $index + 1,
                'Ref' => $row->code,
                'Member' => $row->member_name,
                'Member Code' => $row->member_code,
                'Phone' => $row->member_phone,
                'Product' => $row->product,
                'Balance' => $row->blc,
                'Amount' => $row->amount,
                'Charge' => $row->charge,
                'Status' => $row->status,
                'Date' => $row->transaction_date,
                'Narration' => $row->narration,
            ];
        });
    }
}
