<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

class CustomerPointsController extends Controller
{
    public function __invoke(Customer $customer): JsonResponse
    {
        return response()->json([
            'data' => [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'points_balance' => $customer->points_balance,
            ],
        ]);
    }
}
