<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class GroupMemberSavingsExport implements FromCollection, WithHeadings, WithMapping
{
    protected $data;

    public function __construct($data)
    {
        $this->data = collect($data);
    }

    public function collection(): Collection
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            '#',
            'Member Name',
            'Member Code',
            'Phone',
            'Status',
            'Group Code',
            'Loan Balance',
            'Joined Date',
        ];
    }

    public function map($member): array
    {
        static $index = 0;
        $index++;

        return [
            $index,
            $member->member_name ?? '',
            $member->member_code ?? '',
            $member->phone ?? '',
            $member->member_status ?? '',
            $member->member_group_code ?? '',
            (float) ($member->loan_balance ?? 0),
            isset($member->created_at)
                ? date('d M Y', strtotime($member->created_at))
                : '',
        ];
    }
}
