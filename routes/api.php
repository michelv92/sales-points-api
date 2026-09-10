<?php

use App\Http\Controllers\Api\CustomerPointsController;
use App\Http\Controllers\Api\SaleImportController;
use App\Http\Controllers\Api\WebhookSaleController;
use App\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/sales', WebhookSaleController::class)
    ->middleware(VerifyWebhookSignature::class)
    ->name('webhooks.sales.store');

Route::get('/customers/{customer}/points', CustomerPointsController::class)
    ->middleware('auth:sanctum')
    ->name('customers.points.show');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/imports/sales', [SaleImportController::class, 'store'])
        ->name('sale-imports.store');

    Route::get('/imports/sales/{saleImport}', [SaleImportController::class, 'show'])
        ->name('sale-imports.show');

    Route::get('/imports/sales/{saleImport}/rows', [SaleImportController::class, 'rows'])
        ->name('sale-imports.rows');
});
