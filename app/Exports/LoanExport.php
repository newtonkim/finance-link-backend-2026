<?php

namespace App\Exports;

use App\Tenant\Modules\Loans\Enums\LoanStatus;
use App\Tenant\Modules\Loans\Models\Loan;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LoanExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    public function query()
    {
        $query = Loan::query()
            ->with(['member', 'loanProduct', 'loanApplication'])
            ->orderByDesc('disbursed_at');

        // Apply filters (same as in LoanController)
        if (! empty($this->filters['tab'])) {
            $tab = $this->filters['tab'];
            match ($tab) {
                'disbursed' => $query->whereIn('status', [LoanStatus::Disbursed, LoanStatus::Active, LoanStatus::Closed]),
                'arrears' => $query->where('status', LoanStatus::Arrears),
                'closed' => $query->where('status', LoanStatus::Closed),
                'approved' => $query->where('status', LoanStatus::Approved),
                default => null,
            };
        }

        if (! empty($this->filters['status'])) {
            $query->where('status', $this->filters['status']);
        }

        if (! empty($this->filters['loan_product_id'])) {
            $query->where('loan_product_id', (int) $this->filters['loan_product_id']);
        }

        if (! empty($this->filters['member_name'])) {
            $search = $this->filters['member_name'];
            $query->whereHas('member', fn ($q) => $q->where('name', 'ilike', "%{$search}%"));
        }

        if (! empty($this->filters['search'])) {
            $search = $this->filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('loan_no', 'ilike', "%{$search}%")
                    ->orWhereHas('member', fn ($m) => $m->where('name', 'ilike', "%{$search}%"));
            });
        }

        if (! empty($this->filters['approved_date_from'])) {
            $query->whereHas('loanApplication', fn ($q) => $q->whereDate('approved_at', '>=', $this->filters['approved_date_from']));
        }

        if (! empty($this->filters['approved_date_to'])) {
            $query->whereHas('loanApplication', fn ($q) => $q->whereDate('approved_at', '<=', $this->filters['approved_date_to']));
        }

        if (! empty($this->filters['disbursed_date_from'])) {
            $query->whereDate('disbursed_at', '>=', $this->filters['disbursed_date_from']);
        }

        if (! empty($this->filters['disbursed_date_to'])) {
            $query->whereDate('disbursed_at', '<=', $this->filters['disbursed_date_to']);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Loan No',
            'Customer Name',
            'Member Number',
            'Principal',
            'Current Balance',
            'Approval Date',
            'Disbursement Date',
            'Loan Product',
            'Status',
        ];
    }

    public function map($loan): array
    {
        return [
            $loan->loan_no,
            $loan->member?->name ?? '—',
            $loan->member?->member_number ?? $loan->member?->code ?? '—',
            (float) $loan->principal,
            (float) $loan->outstanding_balance,
            $loan->loanApplication?->approved_at?->format('Y-m-d') ?? '—',
            $loan->disbursed_at?->format('Y-m-d') ?? '—',
            $loan->loanProduct?->name ?? '—',
            ucfirst(str_replace('_', ' ', $loan->status->value ?? $loan->status)),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
