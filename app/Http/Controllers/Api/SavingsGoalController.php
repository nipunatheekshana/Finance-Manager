<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavingsGoalRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Models\SavingsGoal;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SavingsGoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = $request->user()->savingsGoals()
            ->when(! $request->boolean('include_archived'), fn ($q) => $q->where('status', '!=', 'archived'))
            ->orderBy('priority')
            ->orderBy('name')
            ->get();

        return SavingsGoalResource::collection($goals)->additional([
            'meta' => [
                'total_saved' => Money::sum($goals->pluck('current_amount')),
                'total_target' => Money::sum($goals->pluck('target_amount')),
                'total_monthly_target' => Money::sum($goals->pluck('monthly_target')),
            ],
        ]);
    }

    public function store(SavingsGoalRequest $request): JsonResponse
    {
        $goal = $request->user()->savingsGoals()->create($request->validated());

        return response()->json(['data' => new SavingsGoalResource($goal)], 201);
    }

    public function show(SavingsGoal $savingsGoal): JsonResponse
    {
        $this->authorize('view', $savingsGoal);

        return response()->json([
            'data' => new SavingsGoalResource(
                $savingsGoal->load(['transactions' => fn ($q) => $q->orderByDesc('transaction_date')->orderByDesc('id')])
            ),
        ]);
    }

    public function update(SavingsGoalRequest $request, SavingsGoal $savingsGoal): JsonResponse
    {
        $this->authorize('update', $savingsGoal);

        $savingsGoal->update($request->validated());

        return response()->json(['data' => new SavingsGoalResource($savingsGoal)]);
    }

    public function destroy(SavingsGoal $savingsGoal): JsonResponse
    {
        $this->authorize('delete', $savingsGoal);

        // Goals with a transaction history are archived, not destroyed.
        if ($savingsGoal->transactions()->exists()) {
            $savingsGoal->update(['status' => 'archived']);

            return response()->json([
                'message' => 'This goal has a history, so it has been archived instead of deleted.',
                'data' => new SavingsGoalResource($savingsGoal),
            ]);
        }

        $savingsGoal->delete();

        return response()->json(['message' => 'Savings goal deleted.']);
    }
}
