<?php

namespace Database\Factories;

use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Tenant\Modules\Savings\Models\SavingsProductCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsProductChargeFactory extends Factory
{
    protected $model = SavingsProductCharge::class;

    public function definition(): array
    {
        return [
            'savings_product_id' => SavingsProduct::factory(),
            'general_charge_id' => null,
            'name' => fake()->words(2, true) . ' Fee',
            'type' => fake()->randomElement(['deposit', 'withdraw', 'transfer']),
            'minimum_amount' => 0,
            'maximum_amount' => null,
            'charge_type' => 'amount',
            'amount' => fake()->randomFloat(2, 1, 50),
            'is_reversible' => true,
        ];
    }
}
