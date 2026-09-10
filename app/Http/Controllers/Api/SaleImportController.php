<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSaleImportRequest;
use App\Jobs\ProcessSaleCsvImport;
use App\Models\SaleImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SaleImportController extends Controller
{
    public function store(StoreSaleImportRequest $request): JsonResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw new RuntimeException('The uploaded CSV file is unavailable.');
        }

        $disk = 'local';
        $path = $file->store('sale-imports', $disk);

        if ($path === false) {
            throw new RuntimeException('The CSV file could not be stored.');
        }

        try {
            $import = SaleImport::query()->create([
                'user_id' => $request->user()->id,
                'original_filename' => $file->getClientOriginalName(),
                'stored_path' => $path,
                'disk' => $disk,
                'mime_type' => $file->getMimeType(),
                'file_size' => $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        $import->refresh();

        ProcessSaleCsvImport::dispatch($import->id);

        return response()->json([
            'message' => 'CSV import accepted for processing.',
            'data' => $this->serializeImport($import),
        ], Response::HTTP_ACCEPTED);
    }

    public function show(
        SaleImport $saleImport,
    ): JsonResponse {
        abort_unless(
            $saleImport->user_id === request()->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        return response()->json([
            'data' => $this->serializeImport($saleImport),
        ]);
    }

    public function rows(
        Request $request,
        SaleImport $saleImport,
    ): JsonResponse {
        abort_unless(
            $saleImport->user_id === $request->user()->id,
            Response::HTTP_NOT_FOUND,
        );

        $validated = $request->validate([
            'status' => [
                'nullable',
                Rule::in(['processed', 'ignored', 'error']),
            ],
            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:100',
            ],
        ]);

        $rows = $saleImport->rows()
            ->when(
                $validated['status'] ?? null,
                fn ($query, string $status) => $query->where('status', $status),
            )
            ->orderBy('line_number')
            ->paginate((int) ($validated['per_page'] ?? 25));

        return response()->json($rows);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function serializeImport(SaleImport $import): array
    {
        return [
            'id' => $import->id,
            'filename' => $import->original_filename,
            'status' => $import->status,
            'total_rows' => $import->total_rows,
            'processed_rows' => $import->processed_rows,
            'ignored_rows' => $import->ignored_rows,
            'error_rows' => $import->error_rows,
            'error_message' => $import->error_message,
        ];
    }
}
