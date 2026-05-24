<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class OpeningBalancesTemplateExport implements FromArray, WithEvents
{
    public function array(): array
    {
        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();

                $sheet->mergeCells('A1:E1');
                $sheet->setCellValue('A1',
                    'OPENING BALANCES IMPORT  |  Fields marked (*) are required  |  Date format: YYYY-MM-DD'
                );
                $sheet->getStyle('A1')->applyFromArray([
                    'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E3A5F']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getRowDimension(1)->setRowHeight(22);

                $headers = [
                    'A' => 'Member Number *',
                    'B' => 'Account Number *',
                    'C' => 'Opening Balance *',
                    'D' => 'As Of Date * (YYYY-MM-DD)',
                    'E' => 'Notes',
                ];

                foreach ($headers as $col => $label) {
                    $sheet->setCellValue("{$col}2", $label);
                }

                $sheet->getStyle('A2:E2')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D5FA6']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1A4A8A']]],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(30);

                foreach (['A' => 20, 'B' => 22, 'C' => 20, 'D' => 26, 'E' => 35] as $col => $width) {
                    $sheet->getColumnDimension($col)->setWidth($width);
                }

                $sheet->getStyle('C3:C1000')->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('D3:D1000')->getNumberFormat()->setFormatCode('YYYY-MM-DD');
                $sheet->getStyle('A3:E1000')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FAFBFF']],
                ]);
                $sheet->freezePane('A3');
                $sheet->setAutoFilter('A2:E2');
            },
        ];
    }
}
