<?php

namespace App\Tenant\Modules\Accounting\Services;

use App\Tenant\Modules\Accounting\Models\ChartOfAccount;
use App\Tenant\Modules\Accounting\Repositories\ChartOfAccountRepositoryInterface;
use App\Tenant\Modules\Savings\Models\GeneralCharge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ChartOfAccountService
{
    public function __construct(
        protected ChartOfAccountRepositoryInterface $repository
    ) {}

    public function getAllAccounts(): Collection
    {
        return $this->repository->all();
    }

    public function getPaginatedAccounts(int $perPage = 15, ?string $search = null)
    {
        return $this->repository->paginate($perPage, $search);
    }

    public function getAccountTree(): Collection
    {
        return $this->repository->getTree();
    }

    public function getAccountById(int $id): ?ChartOfAccount
    {
        return $this->repository->findById($id);
    }

    public function createAccount(array $data): ChartOfAccount
    {
        return DB::transaction(function () use ($data) {
            // Determine level based on parent
            if (! empty($data['parent_id'])) {
                $parent = $this->repository->findById($data['parent_id']);
                if ($parent) {
                    $data['level'] = $parent->level + 1;
                    // Inherit account type and normal balance from parent if not provided
                    $data['account_type'] = $data['account_type'] ?? $parent->account_type;
                    $data['normal_balance'] = $data['normal_balance'] ?? $parent->normal_balance;
                }
            } else {
                $data['level'] = 1;
            }

            return $this->repository->create($data);
        });
    }

    public function updateAccount(int $id, array $data): ChartOfAccount
    {
        $account = $this->repository->findById($id);
        if (! $account) {
            throw new \Exception("Account with ID {$id} not found.");
        }

        // If parent changes, update level
        if (isset($data['parent_id']) && $data['parent_id'] != $account->parent_id) {
            if ($data['parent_id']) {
                $newParent = $this->repository->findById($data['parent_id']);
                $data['level'] = $newParent ? $newParent->level + 1 : 1;
            } else {
                $data['level'] = 1;
            }
        }

        $this->repository->update($id, $data);

        return $this->repository->findById($id);
    }

    public function deleteAccount(int $id): bool
    {
        $account = $this->repository->findById($id);
        if (! $account) {
            return false;
        }

        // Check if it has children
        if ($account->children()->count() > 0) {
            throw new \Exception('Cannot delete account with sub-accounts.');
        }

        // Check if it is referenced by a general charge
        if (GeneralCharge::on('tenant')->where('credit_account_id', $id)->exists()) {
            throw new \Exception('Cannot delete account referenced by a general charge.');
        }

        // Check if it has transactions (Journal Entry Lines)
        if ($account->journalEntryLines()->count() > 0) {
            throw new \Exception('Cannot delete account with transactions.');
        }

        return $this->repository->delete($id);
    }
}
