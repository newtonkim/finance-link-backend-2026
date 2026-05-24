<?php

namespace App\Central\Http\Controllers;

use App\Central\Services\LicenseService;
use App\Central\Services\StaffService;

class StaffController extends StaffService
{
    public function __construct(
        protected LicenseService $licenseService
    ) {}

    public function index()
    {
        return $this->staffListCollection();
    }

    public function get_staff_list()
    {
        return $this->Response(['data' => $this->staffListCollection()]);
    }

    public function users_drop_down()
    {
        return $this->Response(['data' => $this->staffDropdownCollection()]);
    }

    public function roles_drop_down()
    {
        return $this->Response(['data' => $this->rolesDropdown()]);
    }

    public function staff_create()
    {
        return $this->Response(['data' => $this->staffNewRecord()]);
    }

    public function get_staff_details()
    {
        return $this->Response(['data' => $this->staffDetails()]);
    }

    public function delete_staff()
    {
        return $this->Response(['data' => $this->staffDelete()]);
    }
}
