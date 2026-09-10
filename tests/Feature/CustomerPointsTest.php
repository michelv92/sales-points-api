<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerPointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_request_cannot_view_points(): void
    {
        $customer = Customer::factory()->create([
            'points_balance' => 125,
        ]);

        $this->getJson("/api/customers/{$customer->id}/points")
            ->assertUnauthorized();
    }

    public function test_authenticated_request_can_view_points(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $customer = Customer::factory()->create([
            'points_balance' => 125,
        ]);

        $this->getJson("/api/customers/{$customer->id}/points")
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'customer_id' => $customer->id,
                    'name' => $customer->name,
                    'points_balance' => 125,
                ],
            ]);
    }

    public function test_authenticated_request_returns_not_found_for_unknown_customer(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/customers/999999/points')
            ->assertNotFound();
    }
}
