<?php

namespace Tests\Feature\Central\Models;

use App\Central\Models\License;
use App\Central\Models\Plan;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Support\Str;

it('automatically generates a uuid when created', function () {
    // 1. Arrange
    $tenant = Tenant::factory()->create();
    $plan = Plan::firstOrCreate(['slug' => 'basic'], [
        'name' => 'Basic Plan',
        'price' => 29.99,
        'billing_cycle' => 'monthly',
    ]);

    // 2. Act
    $license = License::create([
        'tenant_id' => $tenant->id,
        'plan' => $plan->slug,
        'starts_at' => now(),
        'expires_at' => now()->addDays(30),
        'status' => 'active',
    ]);

    // 3. Assert
    expect($license->id)->not->toBeNull();
    expect(Str::isUuid($license->id))->toBeTrue();

    // Also verify it was persisted nicely to DB with that UUID
    $this->assertDatabaseHas('licenses', [
        'id' => $license->id,
        'tenant_id' => $tenant->id,
        'plan' => 'basic',
    ]);
});
