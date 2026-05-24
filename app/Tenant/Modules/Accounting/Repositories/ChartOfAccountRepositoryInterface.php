<?php

namespace App\Tenant\Modules\Accounting\Repositories;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Collection;

interface ChartOfAccountRepositoryInterface
{
    public function all(): Collection;

    public function paginate(int $perPage = 15, ?string $search = null);

    public function findById(int $id): ?ChartOfAccount;

    public function create(array $data): ChartOfAccount;

    public function update(int $id, array $data): bool;

    public function delete(int $id): bool;

    public function getTree(): Collection;
}
