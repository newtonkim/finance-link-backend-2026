<?php

namespace Tests\Feature\Central;

use Tests\Concerns\RefreshTenantDatabase;
use Tests\TestCase;

class CentralRouteAuthTest extends TestCase
{
    use RefreshTenantDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.central_domain' => 'central.mfukopro.test',
            'app.central_domains' => ['central.mfukopro.test'],
        ]);
    }

    public function test_central_routes_require_authenticated_central_user(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Host' => 'central.mfukopro.test'])
            ->postJson('/api/v1/central/licenses/list');

        $response->assertUnauthorized();
    }
}
