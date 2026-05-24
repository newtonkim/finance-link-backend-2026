<?php

namespace Database\Factories;

use App\Tenant\Modules\Savings\Models\GeneralCharge;
use Illuminate\Database\Eloquent\Factories\Factory;

class GeneralChargeFactory extends Factory
{
    protected $model = GeneralCharge::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' Charge',
            'is_revenue' => true,
            'application' => 'other',
            'where_to_apply' => 'savings',
            'is_fine' => false,
            'charge_type' => fake()->randomElement(['amount', 'percentage']),
            'amount' => fake()->randomFloat(2, 1, 100),
            'interval_type' => fake()->randomElement(['deposit', 'withdraw', 'transfer']),
            'interval' => null,
            'credit_account_id' => null,
            'is_reversible' => true,
            'is_active' => true,
            'branch_id' => null,
        ];
    }
}
