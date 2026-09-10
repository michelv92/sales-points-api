<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Customer;
use App\Models\PointTransaction;
use App\Models\Sale;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSalePoints implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $saleId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 60];
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            /*
             * Bloqueia a venda para que duas execuções simultâneas do mesmo
             * Job não consigam processá-la ao mesmo tempo.
             */
            $sale = Sale::query()
                ->lockForUpdate()
                ->find($this->saleId);

            if ($sale === null) {
                Log::warning('Sale not found during points processing.', [
                    'sale_id' => $this->saleId,
                ]);

                return;
            }

            if ($sale->status === 'processed') {
                Log::info('Duplicate points processing ignored.', [
                    'sale_id' => $sale->id,
                    'external_id' => $sale->external_id,
                ]);

                return;
            }

            /*
             * Vendas diferentes do mesmo cliente também serão serializadas,
             * impedindo que uma atualização de saldo sobrescreva a outra.
             */
            $customer = Customer::query()
                ->lockForUpdate()
                ->findOrFail($sale->customer_id);

            /*
             * BC Math evita conversões imprecisas por ponto flutuante.
             * Escala zero descarta a fração, conforme a regra do exercício.
             */
            $points = (int) bcdiv($sale->amount, '10', 0);

            PointTransaction::query()->create([
                'sale_id' => $sale->id,
                'customer_id' => $customer->id,
                'points' => $points,
            ]);

            /*
             * O incremento ocorre diretamente no banco e dentro da mesma
             * transação que cria o histórico e marca a venda como processada.
             */
            $customer->increment('points_balance', $points);

            $sale->forceFill([
                'status' => 'processed',
                'points_processed_at' => now(),
                'processing_error' => null,
            ])->save();

            Log::info('Sale points processed.', [
                'sale_id' => $sale->id,
                'customer_id' => $customer->id,
                'points' => $points,
            ]);
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Sale points processing failed permanently.', [
            'sale_id' => $this->saleId,
            'exception' => $exception?->getMessage(),
        ]);

        Sale::query()
            ->whereKey($this->saleId)
            ->where('status', 'pending')
            ->update([
                'status' => 'failed',
                'processing_error' => $exception?->getMessage(),
            ]);
    }
}
