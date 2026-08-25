<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavingsTransactionRequest;
use App\Http\Resources\SavingsGoalResource;
use App\Http\Resources\SavingsTransactionResource;
use App\Models\SavingsGoal;
use App\Models\SavingsTransaction;
use App\Services\SavingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use InvalidArgumentException;

class SavingsTransactionController extends Controller
{
    public function __construct(private readonly SavingsService $savings) {}

    public function index(Request $request, SavingsGoal $savingsGoal): AnonymousResourceCollection
    {
        $this->authorize('view', $savingsGoal);

        return SavingsTransactionResource::collection(
            $savingsGoal->transactions()
                ->orderByDesc('transaction_date')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 25))
        );
    }

    public function store(SavingsTransactionRequest $request, SavingsGoal $savingsGoal): JsonResponse
    {
        $this->authorize('update', $savingsGoal);

        $data = $request->validated();

        try {
            $transaction = match ($data['type']) {
                'deposit' => $this->savings->deposit($savingsGoal, $data),
                'withdrawal' => $this->savings->withdraw($savingsGoal, $data),
                'transfer' => $this->savings->transfer(
                    $savingsGoal,
                    $request->user()->savingsGoals()->findOrFail($data['to_goal_id']),
                    $data,
                )['out'],
            };
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => new SavingsTransactionResource($transaction),
            'goal' => new SavingsGoalResource($savingsGoal->fresh()),
        ], 201);
    }

    public function destroy(SavingsTransaction $savingsTransaction): JsonResponse
    {
        $this->authorize('delete', $savingsTransaction);

        $goal = $savingsTransaction->savingsGoal;
        $this->savings->deleteTransaction($savingsTransaction);

        return response()->json([
            'message' => 'Transaction removed.',
            'goal' => new SavingsGoalResource($goal->fresh()),
        ]);
    }
}
