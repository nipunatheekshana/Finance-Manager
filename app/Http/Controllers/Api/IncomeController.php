<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\IncomeSourceResource;
use App\Http\Resources\IncomeTransactionResource;
use App\Models\IncomeTransaction;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class IncomeController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return IncomeTransactionResource::collection(
            $request->user()->incomeTransactions()
                ->orderByDesc('received_date')
                ->orderByDesc('id')
                ->paginate($request->integer('per_page', 25))
        )->additional([
            'meta' => [
                'sources' => IncomeSourceResource::collection(
                    $request->user()->incomeSources()->get()
                )->resolve(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'received_date' => ['required', 'date'],
            'type' => ['required', 'in:base,extra'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $transaction = $request->user()->incomeTransactions()->create([
            ...$validated,
            'amount' => Money::of($validated['amount']),
        ]);

        return response()->json(['data' => new IncomeTransactionResource($transaction)], 201);
    }

    public function destroy(IncomeTransaction $income): JsonResponse
    {
        $this->authorize('delete', $income);

        $income->delete();

        return response()->json(['message' => 'Income entry removed.']);
    }
}
