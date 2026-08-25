<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AffordabilityRequest;
use App\Services\AffordabilityService;
use Illuminate\Http\JsonResponse;

class AffordabilityController extends Controller
{
    public function __construct(private readonly AffordabilityService $affordability) {}

    public function check(AffordabilityRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->affordability->check($request->user(), $request->validated()['amount']),
        ]);
    }
}
