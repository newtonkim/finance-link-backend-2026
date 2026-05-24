<?php

namespace App\Imports;

use App\Services\Migration\TransactionHistoryMigrationService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class TransactionHistoryImport implements SkipsEmptyRows, ToCollection, WithStartRow
{
    public int $imported = 0;

    public array $errors = [];

    public function __construct(
        private readonly TransactionHistoryMigrationService $service
    ) {}

    public function startRow(): int
    {
        return 3;
    }

    public function collection(Collection $rows): void
    {
        $result = $this->service->process($rows);
        $this->imported = $result['imported'];
        $this->errors = $result['errors'];
    }
}
