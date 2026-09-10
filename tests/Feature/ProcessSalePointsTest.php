<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessSalePoints;
use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSalePointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_calculates_integer_points_and_discards_fraction(): void
    {
        $customer = Customer::factory()->create();

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 355.00,
        ]);

        (new ProcessSalePoints($sale->id))->handle();

        $this->assertDatabaseHas('point_transactions', [
            'sale_id' => $sale->id,
            'customer_id' => $customer->id,
            'points' => 35,
        ]);

        $this->assertSame(
            35,
            $customer->refresh()->points_balance,
        );

        $this->assertSame(
            'processed',
            $sale->refresh()->status,
        );

        $this->assertNotNull($sale->points_processed_at);
    }

    public function test_executing_same_job_twice_does_not_duplicate_points(): void
    {
        $customer = Customer::factory()->create();

        $sale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 350.00,
        ]);

        $job = new ProcessSalePoints($sale->id);

        $job->handle();
        $job->handle();

        $this->assertDatabaseCount('point_transactions', 1);

        $this->assertDatabaseHas('point_transactions', [
            'sale_id' => $sale->id,
            'points' => 35,
        ]);

        $this->assertSame(
            35,
            $customer->refresh()->points_balance,
        );
    }

    public function test_different_sales_accumulate_points_for_same_customer(): void
    {
        $customer = Customer::factory()->create();

        $firstSale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 355.00,
        ]);

        $secondSale = Sale::factory()->create([
            'customer_id' => $customer->id,
            'amount' => 120.50,
        ]);

        (new ProcessSalePoints($firstSale->id))->handle();
        (new ProcessSalePoints($secondSale->id))->handle();

        $this->assertDatabaseCount('point_transactions', 2);

        $this->assertSame(
            47,
            $customer->refresh()->points_balance,
        );
    }
}
