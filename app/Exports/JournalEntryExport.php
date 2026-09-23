<?php

namespace App\Exports;

use App\Tenant\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class JournalEntryExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    protected $fromDate;

    protected $toDate;

    protected $search;

    public function __construct(?string $fromDate = null, ?string $toDate = null, ?string $search = null)
    {
        $this->fromDate = $fromDate;
        $this->toDate = $toDate;
        $this->search = $search;
    }

    public function query(): Builder
    {
        $query = JournalEntry::query()->orderBy('date', 'desc');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('entry_no', 'like', "%{$this->search}%")
                    ->orWhere('narration', 'like', "%{$this->search}%")
                    ->orWhere('reference', 'like', "%{$this->search}%");
            });
        }

        if ($this->fromDate) {
            $query->whereDate('date', '>=', $this->fromDate);
        }

        if ($this->toDate) {
            $query->whereDate('date', '<=', $this->toDate);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'Date',
            'Voucher No',
            'Reference',
            'Description',
            'Type',
            'Total Amount',
            'Status',
            'Created At',
        ];
    }

    public function map($entry): array
    {
        return [
            $entry->date,
            $entry->entry_no,
            $entry->reference,
            $entry->narration,
            $entry->journal_type,
            $entry->total_amount,
            $entry->status,
            $entry->created_at->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
