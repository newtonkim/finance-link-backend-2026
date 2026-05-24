<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanCollectionsExport implements WithMultipleSheets
{
    public function __construct(
        protected array $loanRows,
        protected array $transactionRows,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function sheets(): array
    {
        return [
            new LoanCollectionsSummarySheet($this->loanRows, $this->dateFrom, $this->dateTo),
            new LoanCollectionsTransactionsSheet($this->transactionRows, $this->dateFrom, $this->dateTo),
        ];
    }
}

class LoanCollectionsSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected array $rows,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function title(): string
    {
        return 'Loan Summary';
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn ($row) => [
            $row['member_name'] ?? '',
            $row['member_number'] ?? '',
            $row['loan_no'] ?? '',
            $row['loan_officer_name'] ?? '',
            $row['branch_name'] ?? '',
            $row['product_name'] ?? '',
            (float) ($row['amount_due'] ?? 0),
            (float) ($row['amount_collected'] ?? 0),
            (float) ($row['collection_rate'] ?? 0),
            (float) ($row['outstanding_balance'] ?? 0),
            (int) ($row['days_in_arrears'] ?? 0),
            $row['last_payment_date'] ?? null,
        ]);
    }

    public function headings(): array
    {
        return [
            'Member Name',
            'Member Number',
            'Loan Number',
            'Loan Officer',
            'Branch',
            'Product',
            'Amount Due',
            'Amount Collected',
            'Collection Rate %',
            'Outstanding Balance',
            'Days in Arrears',
            'Last Payment Date',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']],
            ],
            'A1:L1' => ['alignment' => ['horizontal' => 'center']],
            'G1:J1' => ['alignment' => ['horizontal' => 'right']],
        ];
    }
}

class LoanCollectionsTransactionsSheet implements FromCollection, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected array $rows,
        protected string $dateFrom,
        protected string $dateTo,
    ) {}

    public function title(): string
    {
        return 'Transactions';
    }

    public function collection(): Collection
    {
        return collect($this->rows)->map(fn ($row) => [
            $row['loan_no'] ?? '',
            $row['member_name'] ?? '',
            $row['member_number'] ?? '',
            $row['payment_date'] ?? null,
            (float) ($row['amount_paid'] ?? 0),
            (float) ($row['principal_portion'] ?? 0),
            (float) ($row['interest_portion'] ?? 0),
            (float) ($row['charges_portion'] ?? 0),
            (float) ($row['penalty_portion'] ?? 0),
            $row['payment_method'] ?? '',
            $row['receipt_no'] ?? '',
            $row['collected_by_name'] ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'Loan Number',
            'Member Name',
            'Member Number',
            'Payment Date',
            'Amount Paid',
            'Principal Portion',
            'Interest Portion',
            'Charges Portion',
            'Penalty Portion',
            'Payment Method',
            'Receipt Number',
            'Collected By',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DBEAFE']],
            ],
            'A1:L1' => ['alignment' => ['horizontal' => 'center']],
            'E1:I1' => ['alignment' => ['horizontal' => 'right']],
        ];
    }
}
