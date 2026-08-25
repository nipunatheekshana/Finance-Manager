<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\DebtPaymentRequest;
use App\Http\Resources\DebtPaymentResource;
use App\Http\Resources\DebtResource;
use App\Models\Debt;
use App\Models\DebtPayment;
use App\Services\DebtPaymentService;
use App\Services\DebtPayoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DebtPaymentController extends Controller
{
    public function __construct(
        private readonly DebtPaymentService $payments,
        private readonly DebtPayoffService $payoff,
    ) {}

    public function index(Debt $debt): AnonymousResourceCollection
    {
        $this->authorize('view', $debt);

        return DebtPaymentResource::collection(
            $debt->payments()->orderByDesc('payment_date')->orderByDesc('id')->paginate(50)
        );
    }

    public function store(DebtPaymentRequest $request, Debt $debt): JsonResponse
    {
        $this->authorize('update', $debt);

        $payment = $this->payments->recordPayment($debt, $request->validated());
        $debt->refresh();

        return response()->json([
            'data' => new DebtPaymentResource($payment),
            'debt' => new DebtResource($debt),
            // The estimate always reflects the balance as it now stands.
            'payoff' => $this->payoff->project($debt),
        ], 201);
    }

    public function destroy(DebtPayment $payment): JsonResponse
    {
        $this->authorize('delete', $payment);

        $debt = $payment->debt;
        $this->payments->deletePayment($payment);
        $debt->refresh();

        return response()->json([
            'message' => 'Payment removed.',
            'debt' => new DebtResource($debt),
            'payoff' => $this->payoff->project($debt),
        ]);
    }
}
