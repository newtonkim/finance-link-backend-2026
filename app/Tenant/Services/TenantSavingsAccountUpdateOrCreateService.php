<?php

namespace App\Tenant\Services;

use App\Tenant\Services\TenantSavingsAcountServices\Concerns\BuildsSavingsAccountFields;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ImportsSavingsAccountData;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ManagesGroupSavingsAccounts;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ManagesGroupWithdrawalApprovals;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ManagesMemberSavings;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ManagesSavingsTransfers;
use App\Tenant\Services\TenantSavingsAcountServices\Concerns\ProcessesGroupSavingsTransactions;
use App\Tenant\Services\TenantSavingsAcountServices\CrudHelders;

class TenantSavingsAccountUpdateOrCreateService extends CrudHelders
{
    use BuildsSavingsAccountFields;
    use ImportsSavingsAccountData;
    use ManagesGroupSavingsAccounts;
    use ManagesGroupWithdrawalApprovals;
    use ManagesMemberSavings;
    use ManagesSavingsTransfers;
    use ProcessesGroupSavingsTransactions;
}
