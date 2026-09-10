<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'external_id' => 'SALE-'.fake()->unique()->numerify('########'),
            'customer_id' => Customer::factory(),
            'amount' => fake()->randomFloat(2, 1, 10000),
            'occurred_at' => now(),
            'source' => 'webhook',
            'status' => 'pending',
        ];
    }
}
