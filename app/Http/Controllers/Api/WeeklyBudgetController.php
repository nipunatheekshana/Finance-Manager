<?php

namespace App\Http\Controllers\Api;

use App\Enums\AdjustmentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\BudgetAdjustmentRequest;
use App\Http\Resources\BudgetAdjustmentResource;
use App\Models\WeeklyBudget;
use App\Services\BudgetAdjustmentService;
use App\Services\BudgetCalculationService;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

class WeeklyBudgetController extends Controller
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly BudgetAdjustmentService $adjustments,
        private readonly ReportService $reports,
    ) {}

    public function show(WeeklyBudget $weeklyBudget): JsonResponse
    {
        $this->authorize('view', $weeklyBudget);

        return response()->json(['data' => $this->budgets->weeklySummary($weeklyBudget)]);
    }

    /**
     * What the user can do about an overspent week. Nothing is applied here.
     */
    public function adjustmentOptions(WeeklyBudget $weeklyBudget): JsonResponse
    {
        $this->authorize('view', $weeklyBudget);

        return response()->json(['data' => $this->adjustments->optionsFor($weeklyBudget)]);
    }

    /**
     * Apply the option the user explicitly chose.
     */
    public function applyAdjustment(BudgetAdjustmentRequest $request, WeeklyBudget $weeklyBudget): JsonResponse
    {
        $this->authorize('update', $weeklyBudget);

        $validated = $request->validated();

        try {
            $adjustment = $this->adjustments->apply(
                $weeklyBudget,
                AdjustmentType::from($validated['type']),
                $validated,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new BudgetAdjustmentResource($adjustment),
            'week' => $this->budgets->weeklySummary($weeklyBudget->fresh()),
            'weeks' => $this->budgets->weeklySummaries($weeklyBudget->monthlyPlan->fresh('weeklyBudgets')),
        ]);
    }

    /**
     * The end-of-week review.
     */
    public function review(WeeklyBudget $weeklyBudget): JsonResponse
    {
        $this->authorize('view', $weeklyBudget);

        return response()->json([
            'data' => $this->reports->weeklyReview($weeklyBudget),
            'options' => $this->adjustments->optionsFor($weeklyBudget),
        ]);
    }
}
