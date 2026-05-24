<?php

namespace Database\Factories;

use App\Tenant\Modules\Groups\Models\SavingsGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsGroupFactory extends Factory
{
    protected $model = SavingsGroup::class;

    public function definition(): array
    {
        return [
            'code' => 'GRP-' . fake()->unique()->bothify('??###'),
            'name' => fake()->company() . ' Group',
            'primary_contact_country_code' => 'UG',
            'primary_contact_phone' => fake()->phoneNumber(),
            'other_contact_country_code' => 'UG',
            'date_created' => now()->toDateString(),
            'location' => fake()->city(),
            'description' => fake()->sentence(),
            'status' => 'active',
            'created_by' => 1,
        ];
    }
}
