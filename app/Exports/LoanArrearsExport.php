<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanArrearsExport implements FromCollection, WithHeadings, WithStyles
{
    protected array $data;

    protected string $asOfDate;

    public function __construct(array $data, string $asOfDate)
    {
        $this->data = $data;
        $this->asOfDate = $asOfDate;
    }

    public function collection(): Collection
    {
        return collect($this->data)->map(fn ($r) => [
            $r['member_number'],
            $r['member_name'],
            $r['phone'],
            $r['loan_no'],
            $r['product_name'],
            $r['branch_name'],
            $r['loan_officer_name'],
            (float) $r['total_arrears'],
            (float) $r['principal_arrears'],
            (float) $r['interest_arrears'],
            (float) $r['charges_arrears'],
            (float) $r['penalty_arrears'],
            (int) $r['missed_installments'],
            (int) $r['days_in_arrears'],
            $r['last_payment_date'],
            $r['disbursed_at'],
        ]);
    }

    public function headings(): array
    {
        return [
            'Member Number',
            'Member Name',
            'Phone',
            'Loan Number',
            'Product',
            'Branch',
            'Loan Officer',
            'Total Arrears',
            'Principal Arrears',
            'Interest Arrears',
            'Charges Arrears',
            'Penalty Arrears',
            'Missed Installments',
            'Days in Arrears',
            'Last Payment Date',
            'Disbursed Date',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'FEE2E2']]],
            'A1:P1' => ['alignment' => ['horizontal' => 'center']],
            'H1:L1' => ['alignment' => ['horizontal' => 'right']],
        ];
    }
}
