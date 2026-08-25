<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlyPlan;
use App\Services\FinancialPlanService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly FinancialPlanService $plans,
    ) {}

    /**
     * Spending broken down by category for a window, defaulting to the current
     * plan's cycle.
     */
    public function spending(Request $request): JsonResponse
    {
        [$start, $end] = $this->window($request);

        return response()->json([
            'data' => $this->reports->spendingByCategory($request->user(), $start, $end),
            'trend' => $this->reports->spendingTrend(
                $request->user(),
                $request->input('view', 'daily'),
                $start,
                $end,
            ),
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $request->validate(['view' => ['nullable', 'in:daily,weekly,monthly']]);

        [$start, $end] = $this->window($request);

        return response()->json([
            'data' => $this->reports->spendingTrend(
                $request->user(),
                $request->input('view', 'daily'),
                $start,
                $end,
            ),
        ]);
    }

    public function debt(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->debtTrend($request->user(), $request->integer('months', 12)),
        ]);
    }

    public function savings(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->savingsTrend($request->user(), $request->integer('months', 12)),
        ]);
    }

    public function incomeVsExpenses(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->reports->incomeVsExpenses($request->user(), $request->integer('months', 6)),
        ]);
    }

    /**
     * The month-end review for one plan, or the current one.
     */
    public function monthly(Request $request): JsonResponse
    {
        $plan = $request->filled('plan_id')
            ? $request->user()->monthlyPlans()->findOrFail($request->integer('plan_id'))
            : $this->plans->activePlanFor($request->user());

        if ($plan === null) {
            return response()->json([
                'data' => null,
                'message' => 'There is no plan to review yet.',
            ]);
        }

        return response()->json(['data' => $this->reports->monthlyReview($plan)]);
    }

    public function compare(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan_a' => ['required', 'integer'],
            'plan_b' => ['required', 'integer'],
        ]);

        /** @var MonthlyPlan $a */
        $a = $request->user()->monthlyPlans()->findOrFail($validated['plan_a']);
        /** @var MonthlyPlan $b */
        $b = $request->user()->monthlyPlans()->findOrFail($validated['plan_b']);

        return response()->json(['data' => $this->reports->comparePlans($a, $b)]);
    }

    /**
     * Resolve the reporting window: explicit dates, else the active plan cycle,
     * else the calendar month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        if ($request->filled('from') && $request->filled('to')) {
            return [
                CarbonImmutable::parse($request->input('from'))->startOfDay(),
                CarbonImmutable::parse($request->input('to'))->startOfDay(),
            ];
        }

        $plan = $this->plans->activePlanFor($request->user());

        if ($plan !== null) {
            return [
                CarbonImmutable::instance($plan->cycle_start_date),
                CarbonImmutable::instance($plan->cycle_end_date),
            ];
        }

        $today = CarbonImmutable::today();

        return [$today->startOfMonth(), $today->endOfMonth()->startOfDay()];
    }
}
