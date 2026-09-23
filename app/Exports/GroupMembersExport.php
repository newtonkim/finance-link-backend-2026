<?php

namespace App\Exports;

use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class GroupMembersExport implements FromCollection, WithCustomStartCell, WithEvents, WithHeadings, WithMapping, WithStyles
{
    protected $savingsGroup;

    protected $saccoName;

    public function __construct(SavingsGroup $savingsGroup)
    {
        $this->savingsGroup = $savingsGroup;
        $tenant = app('currentTenant');
        $this->saccoName = $tenant ? strtoupper($tenant->name) : 'SACCO';
    }

    public function collection(): Collection
    {
        return $this->savingsGroup->members;
    }

    public function startCell(): string
    {
        return 'A3';
    }

    public function headings(): array
    {
        return [
            'Name',
            'Account No.',
            'Gender',
            'NIN',
            'DOB',
            'Marital Status',
            'Contact',
            'Other Contact',
            'email',
            'Date Joined',
            'Nationality',
        ];
    }

    public function map($member): array
    {
        return [
            $member->name,
            $member->pivot->account_number ?? $member->member_number,
            $member->gender,
            $member->national_id_number,
            $member->dob ? $member->dob->format('d/m/Y') : '',
            $member->marital_status,
            $member->phone,
            $member->other_contact,
            $member->email,
            $member->joined_at ? $member->joined_at->format('d/m/Y') : '',
            $member->nationality,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            // Row 3 (Headings) styling
            3 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '000000'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // Add the Sacco Name Header in Row 1
                $sheet->mergeCells('A1:K1');
                $sheet->setCellValue('A1', $this->saccoName.' MEMBERS');

                $style = [
                    'font' => ['bold' => true, 'size' => 14],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ];

                $sheet->getStyle('A1')->applyFromArray($style);

                // Set auto filter for headings
                $sheet->setAutoFilter('A3:K3');

                // Adjust column widths
                foreach (range('A', 'K') as $columnID) {
                    $sheet->getColumnDimension($columnID)->setAutoSize(true);
                }

                // Add borders to the data table
                $lastRow = $sheet->getHighestRow();
                $cellRange = 'A3:K'.$lastRow;
                $sheet->getStyle($cellRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => '000000'],
                        ],
                    ],
                ]);
            },
        ];
    }
}
