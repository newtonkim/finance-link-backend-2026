<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class TransactionHistoryTemplateExport implements FromArray, WithEvents
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

                $sheet->mergeCells('A1:H1');
                $sheet->setCellValue('A1',
                    'TRANSACTION HISTORY IMPORT  |  Fields marked (*) are required  |  '.
                    'Type: deposit/withdrawal  |  Date: YYYY-MM-DD  |  Mode: cash/bank/mobile_money'
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
                    'C' => 'Transaction Type * (deposit/withdrawal)',
                    'D' => 'Amount *',
                    'E' => 'Transaction Date * (YYYY-MM-DD)',
                    'F' => 'Narration',
                    'G' => 'Payment Mode (cash/bank/mobile_money)',
                    'H' => 'Reference (old system)',
                ];

                foreach ($headers as $col => $label) {
                    $sheet->setCellValue("{$col}2", $label);
                }

                $sheet->getStyle('A2:H2')->applyFromArray([
                    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2D5FA6']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '1A4A8A']]],
                ]);
                $sheet->getRowDimension(2)->setRowHeight(40);

                foreach (['A' => 20, 'B' => 22, 'C' => 30, 'D' => 18, 'E' => 26, 'F' => 35, 'G' => 28, 'H' => 25] as $col => $width) {
                    $sheet->getColumnDimension($col)->setWidth($width);
                }

                $this->addDropdown($sheet, 'C3:C1048576', '"deposit,withdrawal"');
                $this->addDropdown($sheet, 'G3:G1048576', '"cash,bank,mobile_money"');

                $sheet->getStyle('D3:D1000')->getNumberFormat()->setFormatCode('#,##0.00');
                $sheet->getStyle('E3:E1000')->getNumberFormat()->setFormatCode('YYYY-MM-DD');
                $sheet->getStyle('A3:H1000')->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FAFBFF']],
                ]);
                $sheet->freezePane('A3');
                $sheet->setAutoFilter('A2:H2');
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
