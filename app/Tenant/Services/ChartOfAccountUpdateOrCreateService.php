<?php

namespace App\Tenant\Services;

use App\Http\Globals\GlobalHelpers;

class ChartOfAccountUpdateOrCreateService extends GlobalHelpers
{
    //  protected $settings;

    public function __construct()
    {
        // $this->settings = $settings;
    }

    protected function mememberUOrCFields($req)
    {
        return $this->removeAllNullValues([

        ]);
    }
}
