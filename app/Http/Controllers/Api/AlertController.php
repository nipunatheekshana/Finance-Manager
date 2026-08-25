<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FinancialAlertResource;
use App\Models\FinancialAlert;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        // Refresh before listing so the user sees today's state, not yesterday's.
        $alerts = $this->alerts->generateFor($request->user());

        return FinancialAlertResource::collection($alerts);
    }

    public function markRead(FinancialAlert $alert): JsonResponse
    {
        $this->authorize('update', $alert);

        $alert->forceFill(['read_at' => now()])->save();

        return response()->json(['data' => new FinancialAlertResource($alert)]);
    }

    public function dismiss(FinancialAlert $alert): JsonResponse
    {
        $this->authorize('update', $alert);

        $alert->forceFill(['dismissed_at' => now(), 'read_at' => $alert->read_at ?? now()])->save();

        return response()->json(['message' => 'Alert dismissed.']);
    }
}
