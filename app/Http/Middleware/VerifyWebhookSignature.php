<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Webhook-Signature');
        $secret = config('services.webhook.secret');

        if (
            ! is_string($signature)
            || ! is_string($secret)
            || $secret === ''
        ) {
            Log::warning('Webhook signature is missing or not configured.', [
                'ip' => $request->ip(),
            ]);

            return new JsonResponse([
                'message' => 'Invalid webhook signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $expectedSignature = hash_hmac(
            'sha256',
            $request->getContent(),
            $secret,
        );

        if (! hash_equals($expectedSignature, $signature)) {
            Log::warning('Webhook signature validation failed.', [
                'ip' => $request->ip(),
            ]);

            return new JsonResponse([
                'message' => 'Invalid webhook signature.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
