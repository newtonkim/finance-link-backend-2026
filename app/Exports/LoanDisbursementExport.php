<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanDisbursementExport implements WithMultipleSheets
{
    public function __construct(
        protected array $summaryData,
        protected array $loanRows,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function sheets(): array
    {
        return [
            new LoanDisbursementSummarySheet($this->summaryData, $this->dateFrom, $this->dateTo),
            new LoanDisbursementRegisterSheet($this->loanRows, $this->dateFrom, $this->dateTo),
        ];
    }
}

class LoanDisbursementSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected array $summaryData,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function title(): string
    {
        return 'Summary';
    }

    public function collection(): Collection
    {
        $rows = collect();

        // By Product
        foreach ($this->summaryData['by_product'] ?? [] as $item) {
            $rows->push([
                'By Product',
                $item['name'] ?? '',
                (int) ($item['loan_count'] ?? 0),
                (float) ($item['total_amount'] ?? 0),
                (float) ($item['percentage'] ?? 0),
            ]);
        }

        // By Channel
        foreach ($this->summaryData['by_channel'] ?? [] as $item) {
            $rows->push([
                'By Channel',
                $item['name'] ?? '',
                (int) ($item['loan_count'] ?? 0),
                (float) ($item['total_amount'] ?? 0),
                (float) ($item['percentage'] ?? 0),
            ]);
        }

        // By Branch
        foreach ($this->summaryData['by_branch'] ?? [] as $item) {
            $rows->push([
                'By Branch',
                $item['name'] ?? '',
                (int) ($item['loan_count'] ?? 0),
                (float) ($item['total_amount'] ?? 0),
                (float) ($item['percentage'] ?? 0),
            ]);
        }

        // By Officer
        foreach ($this->summaryData['by_officer'] ?? [] as $item) {
            $rows->push([
                'By Officer',
                $item['name'] ?? '',
                (int) ($item['loan_count'] ?? 0),
                (float) ($item['total_amount'] ?? 0),
                (float) ($item['percentage'] ?? 0),
            ]);
        }

        return $rows;
    }

    public function headings(): array
    {
        return ['Category', 'Name', 'Loan Count', 'Total Amount', 'Percentage %'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']],
            ],
        ];
    }
}

class LoanDisbursementRegisterSheet implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected array $rows,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function title(): string
    {
        return 'Register';
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn ($row) => [
            $row['loan_no'] ?? '',
            $row['member_name'] ?? '',
            $row['member_number'] ?? '',
            $row['product_name'] ?? '',
            (float) ($row['principal'] ?? 0),
            (float) ($row['net_disbursed_amount'] ?? 0),
            (float) ($row['interest_rate'] ?? 0),
            (int) ($row['term_months'] ?? 0),
            $row['disbursement_method'] ?? '',
            $row['disbursed_at'] ?? '',
            (float) ($row['repaid_percent'] ?? 0),
            $row['status'] ?? '',
            $row['branch_name'] ?? '',
            $row['loan_officer_name'] ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'Loan No',
            'Member Name',
            'Member Number',
            'Product',
            'Principal',
            'Net Disbursed',
            'Interest Rate %',
            'Term (Months)',
            'Channel',
            'Disbursed Date',
            'Repaid %',
            'Status',
            'Branch',
            'Loan Officer',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
            ],
        ];
    }
}
