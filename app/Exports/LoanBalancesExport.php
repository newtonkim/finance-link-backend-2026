<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanBalancesExport implements FromCollection, WithHeadings, WithStyles
{
    protected $data;

    protected $asOfDate;

    public function __construct(array $data, string $asOfDate)
    {
        $this->data = $data;
        $this->asOfDate = $asOfDate;
    }

    public function collection()
    {
        $rows = collect($this->data)->map(function ($loan) {
            return [
                $loan['member_no'],
                $loan['member_name'],
                $loan['loan_no'],
                $loan['product_name'],
                $loan['branch_name'],
                $loan['loan_officer_name'],
                (float) $loan['principal'],
                (float) $loan['interest_remaining'],
                (float) $loan['charges_remaining'],
                (float) $loan['penalty_remaining'],
                (float) $loan['outstanding_balance'],
                $loan['status'],
                $loan['disbursed_at'],
                $loan['next_due_date'],
                $loan['days_in_arrears'],
            ];
        });

        return $rows;
    }

    public function headings(): array
    {
        return [
            'Member Number',
            'Member Name',
            'Loan Number',
            'Product',
            'Branch',
            'Loan Officer',
            'Principal',
            'Interest',
            'Charges',
            'Penalty',
            'Total Balance',
            'Status',
            'Disbursed Date',
            'Next Due Date',
            'Days in Arrears',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']]],
            'A1:O1' => ['alignment' => ['horizontal' => 'center']],
            'G1:K1' => ['alignment' => ['horizontal' => 'right']],
        ];
    }
}
