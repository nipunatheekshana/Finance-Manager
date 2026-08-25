<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FinancialHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialHealthController extends Controller
{
    public function __construct(private readonly FinancialHealthService $health) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->health->scoreFor($request->user())]);
    }
}
