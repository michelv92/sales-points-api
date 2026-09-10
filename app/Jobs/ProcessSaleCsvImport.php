<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\SalePayloadConflictException;
use App\Models\SaleImport;
use App\Models\SaleImportRow;
use App\Services\Sales\CreateSaleService;
use App\Support\Sales\SaleValidationRules;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class ProcessSaleCsvImport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const array EXPECTED_HEADER = [
        'external_id',
        'customer_id',
        'amount',
        'occurred_at',
    ];

    public int $tries = 3;

    public int $timeout = 1200;

    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $saleImportId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->saleImportId;
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(
        CreateSaleService $createSaleService,
    ): void {
        $import = SaleImport::query()->findOrFail($this->saleImportId);

        if ($import->status === 'completed') {
            Log::info('Completed CSV import execution ignored.', [
                'sale_import_id' => $import->id,
            ]);

            return;
        }

        $import->forceFill([
            'status' => 'processing',
            'started_at' => $import->started_at ?? now(),
            'finished_at' => null,
            'error_message' => null,
        ])->save();

        $stream = Storage::disk($import->disk)
            ->readStream($import->stored_path);

        if (! is_resource($stream)) {
            throw new RuntimeException('The CSV file could not be opened.');
        }

        try {
            $header = fgetcsv($stream, escape: '');

            if ($header === false) {
                throw new UnexpectedValueException('The CSV file is empty.');
            }

            $this->validateHeader($header);

            $lineNumber = 1;

            while (($row = fgetcsv($stream, escape: '')) !== false) {
                $lineNumber++;

                /*
                 * Permite retomar o Job desde o início após uma falha sem
                 * processar novamente linhas já auditadas.
                 */
                if (
                    SaleImportRow::query()
                        ->where('sale_import_id', $import->id)
                        ->where('line_number', $lineNumber)
                        ->exists()
                ) {
                    continue;
                }

                $this->processRow(
                    $import,
                    $lineNumber,
                    $row,
                    $createSaleService,
                );
            }

            $this->synchronizeCounters($import);

            $import->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'error_message' => null,
            ])->save();

            Log::info('CSV import completed.', [
                'sale_import_id' => $import->id,
                'total_rows' => $import->total_rows,
                'processed_rows' => $import->processed_rows,
                'ignored_rows' => $import->ignored_rows,
                'error_rows' => $import->error_rows,
            ]);
        } catch (UnexpectedValueException $exception) {
            $import->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            Log::warning('CSV import rejected.', [
                'sale_import_id' => $import->id,
                'exception' => $exception->getMessage(),
            ]);
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  list<string|null>  $header
     */
    private function validateHeader(array $header): void
    {
        $normalizedHeader = array_map(
            static fn (mixed $column): string => trim((string) $column),
            $header,
        );

        $normalizedHeader[0] = preg_replace(
            '/^\xEF\xBB\xBF/',
            '',
            $normalizedHeader[0],
        ) ?? $normalizedHeader[0];

        if ($normalizedHeader !== self::EXPECTED_HEADER) {
            throw new UnexpectedValueException(
                'Invalid CSV header. Expected: '.implode(',', self::EXPECTED_HEADER),
            );
        }
    }

    /**
     * @param  list<string|null>  $row
     */
    private function processRow(
        SaleImport $import,
        int $lineNumber,
        array $row,
        CreateSaleService $createSaleService,
    ): void {
        if (count($row) !== count(self::EXPECTED_HEADER)) {
            $this->recordError(
                $import,
                $lineNumber,
                $row,
                sprintf(
                    'Expected %d columns, received %d.',
                    count(self::EXPECTED_HEADER),
                    count($row),
                ),
            );

            return;
        }

        $values = array_map(
            static fn (mixed $value): string => trim((string) $value),
            $row,
        );

        /** @var array<string, string> $data */
        $data = array_combine(self::EXPECTED_HEADER, $values);

        $validator = Validator::make(
            $data,
            SaleValidationRules::rules(),
        );

        if ($validator->fails()) {
            $this->recordError(
                $import,
                $lineNumber,
                $row,
                implode(' ', $validator->errors()->all()),
            );

            return;
        }

        try {
            DB::transaction(function () use (
                $import,
                $lineNumber,
                $data,
                $createSaleService,
            ): void {
                $sale = $createSaleService->execute($data, 'csv');
                $created = $sale->wasRecentlyCreated;

                SaleImportRow::query()->create([
                    'sale_import_id' => $import->id,
                    'sale_id' => $sale->id,
                    'line_number' => $lineNumber,
                    'external_id' => $sale->external_id,
                    'status' => $created ? 'processed' : 'ignored',
                    'message' => $created
                        ? 'Sale imported.'
                        : 'Sale already exists.',
                    'raw_data' => $data,
                ]);
            });
        } catch (SalePayloadConflictException $exception) {
            $this->recordError(
                $import,
                $lineNumber,
                $row,
                $exception->getMessage(),
            );
        }
    }

    /**
     * @param  list<string|null>  $row
     */
    private function recordError(
        SaleImport $import,
        int $lineNumber,
        array $row,
        string $message,
    ): void {
        $externalId = isset($row[0])
            ? mb_substr(trim((string) $row[0]), 0, 100)
            : null;

        SaleImportRow::query()->create([
            'sale_import_id' => $import->id,
            'sale_id' => null,
            'line_number' => $lineNumber,
            'external_id' => $externalId !== '' ? $externalId : null,
            'status' => 'error',
            'message' => $message,
            'raw_data' => $row,
        ]);
    }

    private function synchronizeCounters(
        SaleImport $import,
    ): void {
        $counts = SaleImportRow::query()
            ->where('sale_import_id', $import->id)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $import->forceFill([
            'total_rows' => $counts->sum(),
            'processed_rows' => (int) ($counts['processed'] ?? 0),
            'ignored_rows' => (int) ($counts['ignored'] ?? 0),
            'error_rows' => (int) ($counts['error'] ?? 0),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('CSV import failed permanently.', [
            'sale_import_id' => $this->saleImportId,
            'exception' => $exception?->getMessage(),
        ]);

        $import = SaleImport::query()->find($this->saleImportId);

        if ($import === null) {
            return;
        }

        $this->synchronizeCounters($import);

        $import->forceFill([
            'status' => 'failed',
            'finished_at' => now(),
            'error_message' => $exception?->getMessage(),
        ])->save();
    }
}
