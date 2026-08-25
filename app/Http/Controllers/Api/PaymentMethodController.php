<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMethodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $methods = $request->user()->paymentMethods()
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->active())
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return PaymentMethodResource::collection($methods);
    }

    public function store(PaymentMethodRequest $request): JsonResponse
    {
        $method = $request->user()->paymentMethods()->create($request->validated());

        return response()->json(['data' => new PaymentMethodResource($method)], 201);
    }

    public function show(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('view', $paymentMethod);

        return response()->json(['data' => new PaymentMethodResource($paymentMethod)]);
    }

    public function update(PaymentMethodRequest $request, PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('update', $paymentMethod);

        $paymentMethod->update($request->validated());

        return response()->json(['data' => new PaymentMethodResource($paymentMethod)]);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorize('delete', $paymentMethod);

        if ($paymentMethod->expenses()->exists()) {
            $paymentMethod->update(['active' => false]);

            return response()->json([
                'message' => 'This payment method has expenses, so it has been hidden instead of deleted.',
                'data' => new PaymentMethodResource($paymentMethod),
            ]);
        }

        $paymentMethod->delete();

        return response()->json(['message' => 'Payment method deleted.']);
    }
}
