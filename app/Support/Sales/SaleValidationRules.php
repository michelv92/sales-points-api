<?php

declare(strict_types=1);

namespace App\Support\Sales;

class SaleValidationRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'external_id' => [
                'required',
                'string',
                'max:100',
            ],
            'customer_id' => [
                'required',
                'integer',
                'exists:customers,id',
            ],
            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
            ],
            'occurred_at' => [
                'required',
                'date_format:Y-m-d\TH:i:s',
            ],
        ];
    }
}
