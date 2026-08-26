<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MonthlyPlan;
use App\Services\CycleProgressService;
use App\Services\FinancialPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CycleProgressController extends Controller
{
    public function __construct(
        private readonly CycleProgressService $progress,
        private readonly FinancialPlanService $plans,
    ) {}

    /**
     * The current cycle, or any past one by id.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->filled('plan')) {
            // Not found rather than forbidden: another account's plan should
            // not be distinguishable from one that does not exist.
            $plan = MonthlyPlan::query()
                ->where('user_id', $user->id)
                ->findOrFail($request->integer('plan'));

            $this->authorize('view', $plan);

            return response()->json([
                'data' => $this->progress->forPlan($plan),
                'plans' => $this->planOptions($user),
            ]);
        }

        $plan = $this->plans->activePlanFor($user);

        if ($plan === null) {
            return response()->json([
                'data' => null,
                'message' => 'Finalise a monthly plan to track its progress.',
            ]);
        }

        return response()->json([
            'data' => $this->progress->forPlan($plan),
            'plans' => $this->planOptions($user),
        ]);
    }

    /**
     * The cycles worth switching between: finished ones and the live one.
     *
     * @return list<array{id: int, label: string}>
     */
    private function planOptions(\App\Models\User $user): array
    {
        return $user->monthlyPlans()
            ->whereIn('status', ['active', 'completed'])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->limit(12)
            ->get()
            ->map(fn (MonthlyPlan $row) => ['id' => $row->id, 'label' => $row->label()])
            ->all();
    }
}
