<?php

namespace Database\Factories;

use App\Domain\Licensing\Entities\License;
use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<License>
 */
class LicenseFactory extends Factory
{
    protected $model = License::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => Tenant::factory(),
            'plan' => 'Professional',
            'starts_at' => now(),
            'expires_at' => now()->addYear(),
            'status' => 'active',
        ];
    }
}
