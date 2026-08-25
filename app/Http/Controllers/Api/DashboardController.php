<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialAlertResource;
use App\Services\CycleSurplusService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly CycleSurplusService $surplus,
    ) {}

    /**
     * Everything the home screen needs, in one request.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $this->dashboard->build($request->user());

        $data['alerts'] = FinancialAlertResource::collection($data['alerts'])->resolve();

        return response()->json(['data' => $data]);
    }

    /**
     * The most recent finished cycle whose leftover has not been dealt with.
     */
    public function pendingSurplus(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->surplus->pendingFor($request->user())]);
    }
}
