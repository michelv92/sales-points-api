<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleImportRow extends Model
{
    protected $fillable = [
        'sale_import_id',
        'sale_id',
        'line_number',
        'external_id',
        'status',
        'message',
        'raw_data',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'raw_data' => 'array',
        ];
    }

    public function saleImport(): BelongsTo
    {
        return $this->belongsTo(SaleImport::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
