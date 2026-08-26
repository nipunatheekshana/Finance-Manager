<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CycleSurplusRequest;
use App\Http\Requests\MonthlyPlanRequest;
use App\Http\Requests\PlanAllocationsRequest;
use App\Http\Requests\PlanAllowancesRequest;
use App\Http\Requests\PlanFixedExpenseRequest;
use App\Http\Requests\RecordIncomeRequest;
use App\Http\Requests\WeeklyBudgetsRequest;
use App\Http\Resources\MonthlyPlanResource;
use App\Http\Resources\PlanFixedExpenseResource;
use App\Http\Resources\WeeklyBudgetResource;
use App\Models\MonthlyPlan;
use App\Models\PlanFixedExpense;
use App\Services\AuditService;
use App\Services\BudgetCalculationService;
use App\Services\CycleSurplusService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;
use RuntimeException;

class MonthlyPlanController extends Controller
{
    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly BudgetCalculationService $budgets,
        private readonly CycleSurplusService $surplus,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $plans = $request->user()->monthlyPlans()
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->paginate(24);

        return MonthlyPlanResource::collection($plans);
    }

    /**
     * The plan covering today, or a pre-filled draft for the current cycle.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->plans->profileFor($user);

        $plan = $this->plans->activePlanFor($user);

        if ($plan === null) {
            $period = app(\App\Services\BudgetCycleService::class)
                ->currentPeriodFor($profile);

            $plan = $this->plans->draftFor($user, $period['year'], $period['month']);
        }

        return $this->planResponse($plan);
    }

    /**
     * Open (or create) the draft for a specific month.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $plan = $this->plans->draftFor($request->user(), $validated['year'], $validated['month']);

        return $this->planResponse($plan, 201);
    }

    public function show(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return $this->planResponse($monthlyPlan);
    }

    public function update(MonthlyPlanRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $monthlyPlan->fill($request->validated());
        $this->audit->recordChanges(
            $monthlyPlan->user_id,
            'plan.updated',
            $monthlyPlan,
            ['expected_income', 'actual_income', 'buffer', 'allow_deficit'],
        );
        $monthlyPlan->save();

        return $this->planResponse($this->plans->recalculate($monthlyPlan));
    }

    /**
     * Salary-day step 1: record what actually arrived.
     */
    public function recordIncome(RecordIncomeRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $plan = $this->plans->recordActualIncome(
            $monthlyPlan,
            $request->validated()['actual_income'],
            $request->boolean('apply_extra_split', true),
        );

        $plan->incomeTransactions()->create([
            'user_id' => $plan->user_id,
            'amount' => Money::of($request->validated()['actual_income']),
            'received_date' => $request->input('received_date', CarbonImmutable::today()->toDateString()),
            'type' => 'base',
            'description' => 'Salary for '.$plan->label(),
        ]);

        return $this->planResponse($plan);
    }

    /**
     * Salary-day step 2: edit, skip or postpone one bill for this month only.
     */
    public function updateFixedExpense(
        PlanFixedExpenseRequest $request,
        MonthlyPlan $monthlyPlan,
        PlanFixedExpense $fixedExpense,
    ): JsonResponse {
        $this->authorize('update', $monthlyPlan);
        abort_unless($fixedExpense->monthly_plan_id === $monthlyPlan->id, 404);

        $fixedExpense->fill($request->validated());

        if ($fixedExpense->status === 'paid' && $fixedExpense->paid_at === null) {
            $fixedExpense->paid_at = now();
        }

        $this->audit->recordChanges(
            $monthlyPlan->user_id,
            'plan.fixed_expense_updated',
            $fixedExpense,
            ['amount', 'actual_amount', 'status', 'postponed_to'],
        );

        $fixedExpense->save();

        $this->plans->recalculate($monthlyPlan->fresh());

        return response()->json([
            'data' => new PlanFixedExpenseResource($fixedExpense),
            'plan' => new MonthlyPlanResource($this->loadPlan($monthlyPlan->fresh())),
            'summary' => $this->plans->allocationSummary($monthlyPlan->fresh()),
        ]);
    }

    /**
     * Add a one-off bill that only affects this month's plan.
     */
    public function addFixedExpense(Request $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'due_date' => ['nullable', 'date'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $row = $monthlyPlan->fixedExpenses()->create($validated + [
            'occurrences' => 1,
            'status' => 'planned',
        ]);

        $this->plans->recalculate($monthlyPlan->fresh());

        return response()->json([
            'data' => new PlanFixedExpenseResource($row),
            'summary' => $this->plans->allocationSummary($monthlyPlan->fresh()),
        ], 201);
    }

    /**
     * Salary-day steps 3 and 4: how much goes to each debt and each goal.
     */
    public function updateAllocations(PlanAllocationsRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $validated = $request->validated();

        foreach ($validated['debts'] ?? [] as $row) {
            $monthlyPlan->debtAllocations()
                ->where('debt_id', $row['debt_id'])
                ->update(['planned_amount' => Money::of($row['planned_amount'])]);
        }

        foreach ($validated['savings'] ?? [] as $row) {
            $monthlyPlan->savingsAllocations()
                ->where('savings_goal_id', $row['savings_goal_id'])
                ->update(['planned_amount' => Money::of($row['planned_amount'])]);
        }

        return $this->planResponse($this->plans->recalculate($monthlyPlan->fresh()));
    }

    /**
     * The allowances reserved for this cycle, with what has been spent so far.
     */
    public function allowances(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return response()->json([
            'data' => $this->budgets->allowanceSummaries($monthlyPlan),
            'available_categories' => $monthlyPlan->user->categories()
                ->active()
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'icon', 'color', 'monthly_budget', 'is_allowance'])
                ->all(),
            'summary' => $this->plans->allocationSummary($monthlyPlan),
        ]);
    }

    /**
     * Set how much is reserved for each category this cycle. An amount of zero
     * drops the allowance and hands the money back to day-to-day spending.
     */
    public function updateAllowances(PlanAllowancesRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        foreach ($request->validated()['allowances'] as $row) {
            $amount = Money::of($row['amount']);

            if (Money::isPositive($amount)) {
                $monthlyPlan->budgetCategories()->updateOrCreate(
                    ['category_id' => $row['category_id']],
                    ['is_allowance' => true, 'budget_amount' => $amount],
                );

                continue;
            }

            $monthlyPlan->budgetCategories()
                ->where('category_id', $row['category_id'])
                ->where('is_allowance', true)
                ->delete();
        }

        $plan = $this->plans->recalculate($monthlyPlan->fresh());

        return response()->json([
            'data' => $this->budgets->allowanceSummaries($plan),
            'summary' => $this->plans->allocationSummary($plan),
            'plan' => new MonthlyPlanResource($this->loadPlan($plan)),
        ]);
    }

    /**
     * Salary-day step 6: the weekly split.
     */
    public function weeks(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return response()->json([
            'data' => WeeklyBudgetResource::collection($monthlyPlan->weeklyBudgets)->resolve(),
            'suggested' => $this->plans->suggestWeeklyBudgets($monthlyPlan),
            'summaries' => $this->budgets->weeklySummaries($monthlyPlan),
        ]);
    }

    public function updateWeeks(WeeklyBudgetsRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $plan = $this->plans->applyWeeklyBudgets($monthlyPlan, $request->validated()['weeks']);

        return response()->json([
            'data' => WeeklyBudgetResource::collection($plan->weeklyBudgets)->resolve(),
            'summaries' => $this->budgets->weeklySummaries($plan),
        ]);
    }

    /**
     * The step-5 allocation breakdown and over-allocation verdict.
     */
    public function summary(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return response()->json(['data' => $this->plans->allocationSummary($monthlyPlan)]);
    }

    public function finalize(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        try {
            $plan = $this->plans->finalize($monthlyPlan);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'summary' => $this->plans->allocationSummary($monthlyPlan),
            ], 422);
        }

        $this->audit->record($plan->user_id, 'plan.finalized', $plan, null, [
            'spending_budget' => Money::of($plan->spending_budget),
        ]);

        return $this->planResponse($plan);
    }

    public function complete(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $plan = $this->plans->complete($monthlyPlan);
        $this->audit->record($plan->user_id, 'plan.completed', $plan);

        return $this->planResponse($plan);
    }

    /**
     * Reopen a finished month for corrections. Recorded in the audit trail.
     */
    public function reopen(Request $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        $plan = $this->plans->reopen($monthlyPlan);

        $this->audit->record(
            $plan->user_id,
            'plan.reopened',
            $plan,
            ['status' => 'completed'],
            ['status' => 'active'],
            $request->input('reason'),
        );

        return $this->planResponse($plan);
    }

    /**
     * What a finished cycle left over, and the choices for it.
     */
    public function surplus(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return response()->json(['data' => $this->surplus->optionsFor($monthlyPlan)]);
    }

    /**
     * Apply the choice the user made. Nothing moves until this is called.
     */
    public function resolveSurplus(CycleSurplusRequest $request, MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('update', $monthlyPlan);

        try {
            $result = $this->surplus->apply($monthlyPlan, $request->validated()['allocations']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    private function planResponse(MonthlyPlan $plan, int $status = 200): JsonResponse
    {
        $plan = $this->loadPlan($plan);

        return response()->json([
            'data' => new MonthlyPlanResource($plan),
            'summary' => $this->plans->allocationSummary($plan),
            'suggested_weeks' => $this->plans->suggestWeeklyBudgets($plan),
        ], $status);
    }

    private function loadPlan(MonthlyPlan $plan): MonthlyPlan
    {
        return $plan->load([
            'weeklyBudgets',
            'fixedExpenses',
            'debtAllocations.debt',
            'savingsAllocations.savingsGoal',
        ]);
    }
}
