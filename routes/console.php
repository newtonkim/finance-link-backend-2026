<?php

use App\Central\Console\Commands\ExtendLicenseCommand;
use App\Central\Console\Commands\ListTenantsCommand;
use App\Central\Console\Commands\SuspendTenantCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Central Admin Commands
Artisan::command('central:tenant:list', function () {
    (new ListTenantsCommand)->handle();
})->purpose('List all tenants and their current status');

Artisan::command('central:tenant:suspend {subdomain}', function ($subdomain) {
    (new SuspendTenantCommand)->handle($subdomain);
})->purpose('Suspend a tenant by their subdomain');

Artisan::command('central:license:extend {subdomain} {days=30}', function ($subdomain, $days) {
    (new ExtendLicenseCommand)->handle($subdomain, $days);
})->purpose('Extend an active license for a tenant');
