<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DebtRequest;
use App\Http\Resources\DebtResource;
use App\Http\Resources\PaymentMethodResource;
use App\Models\Debt;
use App\Services\CardPaymentMethodService;
use App\Services\DebtPayoffService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DebtController extends Controller
{
    public function __construct(
        private readonly DebtPayoffService $payoff,
        private readonly CardPaymentMethodService $cards,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $debts = $request->user()->debts()
            ->when(! $request->boolean('include_closed'), fn ($q) => $q->active())
            ->orderByDesc('current_balance')
            ->get();

        return DebtResource::collection($debts)->additional([
            'meta' => [
                'total_balance' => Money::sum($debts->pluck('current_balance')),
                'total_planned_payment' => Money::sum($debts->pluck('planned_payment')),
                'total_minimum_payment' => Money::sum($debts->pluck('minimum_payment')),
                'payoff' => $this->payoff->projectAll($debts),
            ],
        ]);
    }

    public function store(DebtRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Without an explicit original amount, today's balance is the baseline
        // that progress is measured against.
        $data['original_amount'] ??= $data['current_balance'];

        $debt = $request->user()->debts()->create($data);

        // A card needs its own payment method before anything can be charged
        // to it.
        $method = $this->cards->ensureFor($debt);

        return response()->json([
            'data' => new DebtResource($debt),
            'payment_method' => $method === null ? null : new PaymentMethodResource($method),
        ], 201);
    }

    public function show(Debt $debt): JsonResponse
    {
        $this->authorize('view', $debt);

        return response()->json([
            'data' => new DebtResource($debt->load(['payments' => fn ($q) => $q->orderByDesc('payment_date')])),
            'payoff' => $this->payoff->project($debt),
        ]);
    }

    public function update(DebtRequest $request, Debt $debt): JsonResponse
    {
        $this->authorize('update', $debt);

        $previousName = $debt->name;

        $debt->update($request->validated());

        // Keep the card's payment method named after it, and create one if the
        // debt has just been changed into a credit card.
        $this->cards->ensureFor($debt);
        $this->cards->syncName($debt, $previousName);

        return response()->json([
            'data' => new DebtResource($debt),
            'payoff' => $this->payoff->project($debt),
        ]);
    }

    public function destroy(Debt $debt): JsonResponse
    {
        $this->authorize('delete', $debt);

        // Hide the card's payment method before the link is nulled, so the
        // expense form stops offering a card that no longer exists.
        $this->cards->releaseFor($debt);

        $debt->delete();

        return response()->json(['message' => 'Debt deleted.']);
    }

    /**
     * The month-by-month payoff estimate, optionally at a different payment.
     */
    public function payoff(Request $request, Debt $debt): JsonResponse
    {
        $this->authorize('view', $debt);

        $validated = $request->validate([
            'monthly_payment' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
        ]);

        return response()->json([
            'data' => $this->payoff->project($debt, $validated['monthly_payment'] ?? null),
        ]);
    }
}
