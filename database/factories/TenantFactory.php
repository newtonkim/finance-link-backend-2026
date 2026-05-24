<?php

namespace Database\Factories;

use App\Domain\Tenancy\Entities\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => fake()->company(),
            'subdomain' => fake()->unique()->word(),
            'database_name' => 'tenant_'.Str::random(10),
            'status' => 'active',
        ];
    }
}
