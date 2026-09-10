<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\SalePayloadConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreWebhookSaleRequest;
use App\Services\Sales\CreateSaleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookSaleController extends Controller
{
    public function __invoke(
        StoreWebhookSaleRequest $request,
        CreateSaleService $createSaleService,
    ): JsonResponse {
        try {
            $sale = $createSaleService->execute(
                $request->validated(),
                'webhook',
            );
        } catch (SalePayloadConflictException $exception) {
            Log::warning('Conflicting duplicate sale received.', [
                'external_id' => $request->string('external_id')->toString(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], Response::HTTP_CONFLICT);
        }

        $created = $sale->wasRecentlyCreated;

        return response()->json([
            'message' => $created
                ? 'Sale accepted for processing.'
                : 'Sale already received.',
            'data' => [
                'id' => $sale->id,
                'external_id' => $sale->external_id,
                'status' => $sale->status,
                'duplicate' => ! $created,
            ],
        ], $created
            ? Response::HTTP_ACCEPTED
            : Response::HTTP_OK);
    }
}
