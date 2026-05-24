<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\DatabaseTransactions;

trait RefreshTenantDatabase
{
    use DatabaseTransactions;

    /**
     * The database connections that should have transactions started.
     *
     * @return array
     */
    protected function connectionsToTransact()
    {
        return ['mysql'];
    }
}
