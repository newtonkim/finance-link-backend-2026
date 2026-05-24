<?php

namespace Database\Factories;

use App\Tenant\Modules\Savings\Models\SavingsAccount;
use App\Tenant\Modules\Savings\Models\SavingsProduct;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

class SavingsAccountFactory extends Factory
{
    protected $model = SavingsAccount::class;

    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'savings_product_id' => SavingsProduct::factory(),
            'account_no' => 'ACC-' . fake()->unique()->numerify('##########'),
            'code' => fake()->unique()->bothify('??###'),
            'account_type' => 'standard',
            'balance' => 0,
            'status' => 'active',
            'branch_id' => 1,
        ];
    }
}
