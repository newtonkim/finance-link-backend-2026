<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class MembersTemplateExport implements FromArray, WithEvents
{
    public function array(): array
    {
        return []; // Empty — this is a blank import template
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                // ── Row 1: Instructions bar ────────────────────────────────
                $sheet->mergeCells('A1:O1');
                $sheet->setCellValue(
                    'A1',
                    'MEMBERS IMPORT TEMPLATE  |  Fields marked (*) are required  |  '.
                    'Gender, Marital Status and Member Type columns have dropdown options  |  '.
                    'Date format: YYYY-MM-DD (e.g. 2015-08-21)'
                );
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_CENTER,
                    ],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(22);

                // ── Row 2: Column headers ──────────────────────────────────
                // Columns: A-O (15 total)
                // Removed: ID Number, Mobile Money Number, Next of Kin,
                //          Next of Kin Contact, Member Type, Salutation
                $headers = [
                    'A' => 'Name *',
                    'B' => 'Phone *',
                    'C' => 'Gender * (male/female/other)',
                    'D' => 'Marital Status * (single/married/divorced/widowed)',
                    'E' => 'Nationality *',
                    'F' => 'Address *',
                    'G' => 'Email',
                    'H' => 'Date of Birth (YYYY-MM-DD)',
                    'I' => 'Other Contact',
                    'J' => 'Initial Deposit',
                    'K' => 'Date Joined (YYYY-MM-DD)',
                    'L' => 'Savings Balance',
                    'M' => 'Shares Quantity',
                    'N' => 'Account Number',
                    'O' => 'Employee Number',
                ];

                foreach ($headers as $col => $label) {
                    $sheet->setCellValue("{$col}2", $label);
                }

                $sheet->getStyle('A2:O2')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D5FA6']],
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical' => Alignment::VERTICAL_CENTER,
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1A4A8A']],
                    ],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(36);

                // ── Column widths ──────────────────────────────────────────
                $widths = [
                    'A' => 25, 'B' => 18, 'C' => 28, 'D' => 38,
                    'E' => 18, 'F' => 30, 'G' => 28, 'H' => 22,
                    'I' => 18, 'J' => 18, 'K' => 22, 'L' => 18,
                    'M' => 18, 'N' => 22, 'O' => 20,
                ];
                foreach ($widths as $col => $width) {
                    $sheet->getColumnDimension($col)->setWidth($width);
                }

                // ── Dropdown validations for enum columns ──────────────────
                // Capped at 1000 rows — applying to the full sheet exhausts memory in PhpSpreadsheet
                $this->addDropdown($sheet, 'C3:C1000', '"male,female,other"');
                $this->addDropdown($sheet, 'D3:D1000', '"single,married,divorced,widowed"');

                // ── Force YYYY-MM-DD display on date columns ───────────────
                $dateFormat = 'YYYY-MM-DD';
                $sheet->getStyle('H3:H1000')->getNumberFormat()->setFormatCode($dateFormat);
                $sheet->getStyle('K3:K1000')->getNumberFormat()->setFormatCode($dateFormat);

                // ── Light background for data area ─────────────────────────
                $sheet->getStyle('A3:O1000')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FAFBFF']],
                ]);

                // Freeze the header rows so they stay visible while scrolling
                $sheet->freezePane('A3');

                // Auto-filter on headers
                $sheet->setAutoFilter('A2:O2');
            },
        ];
    }

    private function addDropdown($sheet, string $range, string $formula): void
    {
        $v = new DataValidation;
        $v->setType(DataValidation::TYPE_LIST);
        $v->setErrorStyle(DataValidation::STYLE_STOP);
        $v->setAllowBlank(true);
        $v->setShowDropDown(true);
        $v->setShowInputMessage(true);
        $v->setShowErrorMessage(true);
        $v->setFormula1($formula);
        $sheet->setDataValidation($range, $v);
    }
}
