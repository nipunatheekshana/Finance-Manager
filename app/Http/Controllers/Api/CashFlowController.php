<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CashFlowService;
use App\Services\FinancialPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashFlowController extends Controller
{
    public function __construct(
        private readonly CashFlowService $cashFlow,
        private readonly FinancialPlanService $plans,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $plan = $this->plans->activePlanFor($request->user());

        if ($plan === null) {
            return response()->json([
                'data' => null,
                'message' => 'Finalise a monthly plan to see your cash flow.',
            ]);
        }

        $plan->loadMissing(['fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal', 'user.financialProfile']);

        return response()->json(['data' => $this->cashFlow->forecast($plan)]);
    }

    /**
     * Every dated financial event in a calendar month.
     */
    public function calendar(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $today = CarbonImmutable::today();

        return response()->json([
            'data' => $this->cashFlow->calendarEvents(
                $request->user(),
                $validated['year'] ?? $today->year,
                $validated['month'] ?? $today->month,
            ),
        ]);
    }
}
