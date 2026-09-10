<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\Sales\SaleValidationRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StoreWebhookSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return SaleValidationRules::rules();
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Invalid webhook payload received.', [
            'errors' => $validator->errors()->toArray(),
            'ip' => $this->ip(),
        ]);

        parent::failedValidation($validator);
    }
}
