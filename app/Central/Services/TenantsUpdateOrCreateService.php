<?php

namespace App\Central\Services;

use App\Central\Services\TenantsService as ServicesTenantsService;
use App\Http\Globals\GlobalHelpers;

class TenantsUpdateOrCreateService extends GlobalHelpers
{
    public function tenantsDelete()
    {
        $this->DeleteRecord('tenants', request());
        $tenantsService = app(ServicesTenantsService::class);

        return $tenantsService->tenantsListCollection();
    }
}
