<?php

namespace App\Concerns;

trait HasDynamicConnection
{
    /**
     * Get the current connection name for the model.
     *
     * @return string|null
     */
    public function getConnectionName()
    {
        return app()->runningUnitTests() ? 'mysql' : $this->connection;
    }
}
