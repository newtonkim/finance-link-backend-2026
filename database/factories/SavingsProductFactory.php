<?php

namespace Database\Factories;

use App\Tenant\Modules\Savings\Models\SavingsProduct;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsProductFactory extends Factory
{
    protected $model = SavingsProduct::class;

    public function definition(): array
    {
        return [
            'code' => 'SP-' . fake()->unique()->bothify('??###'),
            'name' => fake()->words(2, true) . ' Savings',
            'type' => 'standard',
            'minimum_balance' => 0,
            'minimum_maturity_months' => 0,
            'dormancy_period_months' => 12,
            'status' => 'active',
            'charge_on_deposit' => false,
            'charge_on_withdraw' => false,
            'charge_on_transfer' => false,
        ];
    }
}
