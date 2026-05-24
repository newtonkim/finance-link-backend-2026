<?php

namespace App\Contracts;

use Illuminate\Support\Collection;

interface MigrationImportInterface
{
    public function process(Collection $rows): array;
}
