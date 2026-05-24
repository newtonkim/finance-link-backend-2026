<?php

namespace App\Tenant\Modules\Accounting\Repositories;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use Illuminate\Support\Collection;

class ChartOfAccountRepository implements ChartOfAccountRepositoryInterface
{
    public function all(): Collection
    {
        return ChartOfAccount::orderBy('gl_code')->get();
    }

    public function paginate(int $perPage = 15, ?string $search = null)
    {
        $query = ChartOfAccount::orderBy('gl_code');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('gl_code', 'like', "%{$search}%");
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function findById(int $id): ?ChartOfAccount
    {
        return ChartOfAccount::find($id);
    }

    public function create(array $data): ChartOfAccount
    {
        return ChartOfAccount::create($data);
    }

    public function update(int $id, array $data): bool
    {
        $account = $this->findById($id);
        if (! $account) {
            return false;
        }

        return $account->update($data);
    }

    public function delete(int $id): bool
    {
        $account = $this->findById($id);
        if (! $account) {
            return false;
        }

        return $account->delete();
    }

    public function getTree(): Collection
    {
        return ChartOfAccount::with('children')
            ->whereNull('parent_id')
            ->orderBy('gl_code')
            ->get();
    }
}
