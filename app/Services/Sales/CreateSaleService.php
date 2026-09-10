<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Exceptions\SalePayloadConflictException;
use App\Jobs\ProcessSalePoints;
use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateSaleService
{
    /**
     * @param array{
     *     external_id: string,
     *     customer_id: int,
     *     amount: int|float|string,
     *     occurred_at: string
     * } $data
     */
    public function execute(array $data, string $source): Sale
    {
        $occurredAt = CarbonImmutable::createFromFormat(
            'Y-m-d\TH:i:s',
            $data['occurred_at'],
        );

        $normalizedAmount = bcadd((string) $data['amount'], '0', 2);

        return DB::transaction(function () use (
            $data,
            $source,
            $occurredAt,
            $normalizedAmount,
        ): Sale {
            $sale = Sale::query()->firstOrCreate(
                [
                    'external_id' => $data['external_id'],
                ],
                [
                    'customer_id' => $data['customer_id'],
                    'amount' => $normalizedAmount,
                    'occurred_at' => $occurredAt,
                    'source' => $source,
                    'status' => 'pending',
                ],
            );

            if (! $sale->wasRecentlyCreated) {
                $this->ensurePayloadMatches(
                    $sale,
                    (int) $data['customer_id'],
                    $normalizedAmount,
                    $occurredAt,
                );

                Log::info('Duplicate sale received.', [
                    'sale_id' => $sale->id,
                    'external_id' => $sale->external_id,
                    'source' => $source,
                ]);
            } else {
                Log::info('Sale created.', [
                    'sale_id' => $sale->id,
                    'external_id' => $sale->external_id,
                    'source' => $source,
                ]);
            }

            /*
             * A venda pendente também é reenfileirada quando recebida novamente.
             * Isso permite recuperar uma venda salva caso o primeiro envio para
             * o Redis tenha falhado. O Job será idempotente.
             */

            if ($sale->status === 'pending') {
                ProcessSalePoints::dispatch($sale->id)->afterCommit();
            }

            return $sale;
        });
    }

    private function ensurePayloadMatches(
        Sale $sale,
        int $customerId,
        string $amount,
        CarbonImmutable $occurredAt,
    ): void {
        $sameCustomer = $sale->customer_id === $customerId;
        $sameAmount = bccomp($sale->amount, $amount, 2) === 0;
        $sameOccurrence = $sale->occurred_at->equalTo($occurredAt);

        if (! $sameCustomer || ! $sameAmount || ! $sameOccurrence) {
            throw new SalePayloadConflictException($sale->external_id);
        }
    }
}
