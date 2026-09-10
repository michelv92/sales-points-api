<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessSalePoints;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebhookSaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.webhook.secret', 'testing-secret');
    }

    public function test_valid_webhook_stores_sale_and_dispatches_job(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $response = $this->postWebhook($payload);

        $response
            ->assertAccepted()
            ->assertJsonPath('data.external_id', 'SALE-92831')
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('sales', [
            'external_id' => 'SALE-92831',
            'customer_id' => $customer->id,
            'source' => 'webhook',
            'status' => 'pending',
        ]);

        Queue::assertPushed(ProcessSalePoints::class);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();

        $this->postWebhook(
            $this->validPayload($customer->id),
            'invalid-signature',
        )->assertUnauthorized();

        $this->assertDatabaseCount('sales', 0);
        Queue::assertNothingPushed();
    }

    public function test_webhook_with_invalid_payload_is_rejected(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $payload = $this->validPayload($customer->id);
        $payload['amount'] = 0;

        $this->postWebhook($payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('sales', 0);
        Queue::assertNothingPushed();
    }

    public function test_duplicate_webhook_does_not_create_another_sale(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $this->postWebhook($payload)->assertAccepted();

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertDatabaseCount('sales', 1);
    }

    public function test_same_external_id_with_different_data_returns_conflict(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $payload = $this->validPayload($customer->id);

        $this->postWebhook($payload)->assertAccepted();

        $payload['amount'] = 500;

        $this->postWebhook($payload)->assertConflict();

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseHas('sales', [
            'external_id' => 'SALE-92831',
            'amount' => 350,
        ]);
    }

    /**
     * @return array{
     *     external_id: string,
     *     customer_id: int,
     *     amount: float,
     *     occurred_at: string
     * }
     */
    private function validPayload(int $customerId): array
    {
        return [
            'external_id' => 'SALE-92831',
            'customer_id' => $customerId,
            'amount' => 350.00,
            'occurred_at' => '2026-08-20T14:30:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postWebhook(
        array $payload,
        ?string $signature = null,
    ): TestResponse {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $signature ??= hash_hmac(
            'sha256',
            $json,
            'testing-secret',
        );

        return $this->call(
            method: 'POST',
            uri: '/api/webhooks/sales',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            ],
            content: $json,
        );
    }
}
