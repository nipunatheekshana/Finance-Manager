<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AddPlanDebtRequest;
use App\Http\Resources\MonthlyPlanResource;
use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Services\FinancialPlanService;
use App\Services\PlanCommitmentService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PlanCommitmentController extends Controller
{
    public function __construct(
        private readonly PlanCommitmentService $commitments,
        private readonly FinancialPlanService $plans,
    ) {}

    /** Debts that exist but are not in this plan. */
    public function index(MonthlyPlan $monthlyPlan): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);

        return response()->json(['data' => $this->commitments->pendingDebts($monthlyPlan)]);
    }

    /** What each way of paying for it would do. */
    public function options(MonthlyPlan $monthlyPlan, Debt $debt): JsonResponse
    {
        $this->authorize('view', $monthlyPlan);
        $this->authorize('view', $debt);

        return response()->json([
            'data' => $this->commitments->optionsFor(
                $monthlyPlan,
                $debt,
                request()->query('amount') === null ? null : (string) request()->query('amount'),
            ),
        ]);
    }

    public function store(AddPlanDebtRequest $request, MonthlyPlan $monthlyPlan, Debt $debt): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = $this->commitments->add(
                $monthlyPlan,
                $debt,
                $validated['amount'],
                $validated['source'],
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $e) {
            // A refusal the user can act on, not a server fault.
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['source' => [$e->getMessage()]],
            ], 422);
        }

        $plan = $result['plan'];

        return response()->json([
            'data' => new MonthlyPlanResource(
                $plan->load(['fixedExpenses', 'debtAllocations.debt', 'savingsAllocations.savingsGoal', 'weeklyBudgets'])
            ),
            'summary' => $this->plans->allocationSummary($plan),
            'amount' => $result['amount'],
            'source' => $result['source'],
        ]);
    }
}
