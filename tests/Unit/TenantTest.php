<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class TenantTest extends TestCase
{
    public function test_it_correctly_calculates_full_domain_from_central_domain()
    {
        Config::set('app.central_domain', 'admin.mfukopro.test');

        $tenant = new Tenant(['subdomain' => 'wazalendo-sacco']);

        // Should strip 'admin.' and append '.mfukopro.test'
        $this->assertEquals('wazalendo-sacco.mfukopro.test', $tenant->full_domain);

        Config::set('app.central_domain', 'mfukopro.com');
        $this->assertEquals('wazalendo-sacco.mfukopro.com', $tenant->full_domain);
    }

    public function test_it_uses_custom_domain_if_set()
    {
        $tenant = new Tenant([
            'subdomain' => 'wazalendo-sacco',
            'domain' => 'sacco.wazalendo.com',
        ]);

        $this->assertEquals('sacco.wazalendo.com', $tenant->full_domain);
    }

    public function test_it_generates_correct_full_url_for_localhost()
    {
        $tenant = new Tenant(['subdomain' => 'wazalendo-sacco']);

        // Mock a request on localhost:8000
        $request = Request::create('http://localhost:8000/central/tenants', 'GET');
        $this->app->instance('request', $request);

        // On localhost, it should use .localhost suffix
        $this->assertEquals('http://wazalendo-sacco.localhost:8000', $tenant->full_url);
    }

    public function test_it_generates_correct_full_url_for_production()
    {
        Config::set('app.central_domain', 'admin.mfukopro.com');
        $tenant = new Tenant(['subdomain' => 'wazalendo-sacco']);

        // Mock a request on production domain
        $request = Request::create('https://admin.mfukopro.com/central/tenants', 'GET');
        $this->app->instance('request', $request);

        $this->assertEquals('https://wazalendo-sacco.mfukopro.com', $tenant->full_url);
    }

    public function test_it_includes_port_if_non_standard()
    {
        Config::set('app.central_domain', 'admin.mfukopro.test');
        $tenant = new Tenant(['subdomain' => 'wazalendo-sacco']);

        // Mock a request on standard port 80
        $request = Request::create('http://admin.mfukopro.test/central/tenants', 'GET');
        $this->app->instance('request', $request);
        $this->assertEquals('http://wazalendo-sacco.mfukopro.test', $tenant->full_url);

        // Mock a request on non-standard port 8080
        $request = Request::create('http://admin.mfukopro.test:8080/central/tenants', 'GET');
        $this->app->instance('request', $request);
        $this->assertEquals('http://wazalendo-sacco.mfukopro.test:8080', $tenant->full_url);
    }
}
