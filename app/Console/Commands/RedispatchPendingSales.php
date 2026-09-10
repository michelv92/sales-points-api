<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessSalePoints;
use App\Models\Sale;
use Illuminate\Console\Command;

class RedispatchPendingSales extends Command
{
    protected $signature = 'sales:redispatch-pending
                            {--limit=1000 : Maximum number of sales}
                            {--include-failed : Also retry failed sales}';

    protected $description = 'Redispatch sales that were stored but not processed';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $includeFailed = (bool) $this->option('include-failed');

        $query = Sale::query()
            ->where(function ($query) use ($includeFailed): void {
                $query->where(function ($query): void {
                    $query
                        ->where('status', 'pending')
                        ->where('created_at', '<=', now()->subMinutes(5));
                });

                if ($includeFailed) {
                    $query->orWhere('status', 'failed');
                }
            })
            ->orderBy('id');

        $dispatched = 0;

        foreach ($query->lazyById()->take($limit) as $sale) {
            if ($sale->status === 'failed') {
                $sale->forceFill([
                    'status' => 'pending',
                    'processing_error' => null,
                ])->save();
            }

            ProcessSalePoints::dispatch($sale->id);
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} sale(s).");

        return self::SUCCESS;
    }
}
