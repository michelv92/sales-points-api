<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ProcessSaleCsvImport;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleImport;
use App\Models\User;
use App\Services\Sales\CreateSaleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_upload_csv(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->createWithContent(
            'sales.csv',
            $this->validCsv(customerId: 1),
        );

        $this->postJson('/api/imports/sales', [
            'file' => $file,
        ])->assertUnauthorized();

        $this->assertDatabaseCount('sale_imports', 0);
    }

    public function test_authenticated_user_can_upload_csv_for_async_processing(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->createWithContent(
            'sales.csv',
            $this->validCsv($customer->id),
        );

        $this->post('/api/imports/sales', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])
            ->assertAccepted()
            ->assertJsonPath('data.status', 'pending');

        $import = SaleImport::query()->firstOrFail();

        $this->assertSame($user->id, $import->user_id);

        $this->assertTrue(
            Storage::disk('local')->exists($import->stored_path),
            'The uploaded CSV file was not stored.',
        );

        Queue::assertPushed(
            ProcessSaleCsvImport::class,
            fn (ProcessSaleCsvImport $job): bool => $job->saleImportId === $import->id,
        );
    }

    public function test_csv_continues_after_invalid_row_and_ignores_webhook_duplicate(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        Sale::factory()->create([
            'external_id' => 'SALE-WEBHOOK',
            'customer_id' => $customer->id,
            'amount' => 120.50,
            'occurred_at' => '2026-08-20 14:35:00',
            'source' => 'webhook',
            'status' => 'pending',
        ]);

        $csv = implode("\n", [
            'external_id,customer_id,amount,occurred_at',
            "SALE-CSV-1,{$customer->id},350.00,2026-08-20T14:30:00",
            'SALE-INVALID,999999,90.00,2026-08-20T14:32:00',
            "SALE-WEBHOOK,{$customer->id},120.50,2026-08-20T14:35:00",
        ]);

        $import = $this->createImport($user, $csv);

        (new ProcessSaleCsvImport($import->id))
            ->handle(app(CreateSaleService::class));

        $import->refresh();

        $this->assertSame('completed', $import->status);
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(1, $import->processed_rows);
        $this->assertSame(1, $import->ignored_rows);
        $this->assertSame(1, $import->error_rows);

        $this->assertDatabaseCount('sales', 2);

        $this->assertDatabaseHas('sale_import_rows', [
            'sale_import_id' => $import->id,
            'external_id' => 'SALE-CSV-1',
            'status' => 'processed',
        ]);

        $this->assertDatabaseHas('sale_import_rows', [
            'sale_import_id' => $import->id,
            'external_id' => 'SALE-INVALID',
            'status' => 'error',
        ]);

        $this->assertDatabaseHas('sale_import_rows', [
            'sale_import_id' => $import->id,
            'external_id' => 'SALE-WEBHOOK',
            'status' => 'ignored',
        ]);
    }

    public function test_invalid_csv_header_marks_import_as_failed(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();

        $import = $this->createImport(
            $user,
            "wrong,header\nvalue,value",
        );

        (new ProcessSaleCsvImport($import->id))
            ->handle(app(CreateSaleService::class));

        $import->refresh();

        $this->assertSame('failed', $import->status);
        $this->assertStringContainsString(
            'Invalid CSV header',
            (string) $import->error_message,
        );
        $this->assertDatabaseCount('sales', 0);
    }

    public function test_owner_can_list_and_filter_import_rows(): void
    {
        Storage::fake('local');
        Queue::fake();

        $user = User::factory()->create();
        $customer = Customer::factory()->create();

        $csv = implode("\n", [
            'external_id,customer_id,amount,occurred_at',
            "SALE-CSV-1,{$customer->id},350.00,2026-08-20T14:30:00",
            'SALE-INVALID,999999,90.00,2026-08-20T14:32:00',
        ]);

        $import = $this->createImport($user, $csv);

        (new ProcessSaleCsvImport($import->id))
            ->handle(app(CreateSaleService::class));

        Sanctum::actingAs($user);

        $this->getJson(
            "/api/imports/sales/{$import->id}/rows?status=error",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.external_id', 'SALE-INVALID')
            ->assertJsonPath('data.0.status', 'error');
    }

    private function createImport(
        User $user,
        string $content,
    ): SaleImport {
        $path = 'sale-imports/testing.csv';

        Storage::disk('local')->put($path, $content);

        return SaleImport::query()->create([
            'user_id' => $user->id,
            'original_filename' => 'testing.csv',
            'stored_path' => $path,
            'disk' => 'local',
            'mime_type' => 'text/csv',
            'file_size' => strlen($content),
        ]);
    }

    private function validCsv(int $customerId): string
    {
        return implode("\n", [
            'external_id,customer_id,amount,occurred_at',
            "SALE-CSV-1,{$customerId},350.00,2026-08-20T14:30:00",
        ]);
    }
}
