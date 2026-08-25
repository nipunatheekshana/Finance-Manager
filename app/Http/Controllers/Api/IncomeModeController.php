<?php

namespace App\Http\Controllers\Api;

use App\Enums\IncomeMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\IncomeModeRequest;
use App\Http\Resources\FinancialProfileResource;
use App\Services\FinancialPlanService;
use App\Services\IncomeModeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IncomeModeController extends Controller
{
    public function __construct(
        private readonly IncomeModeService $modes,
        private readonly FinancialPlanService $plans,
    ) {}

    /**
     * The modes on offer, and the funding methods each one allows.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->plans->profileFor($request->user());

        return response()->json([
            'data' => [
                'current' => [
                    'income_mode' => $profile->income_mode->value,
                    'cycle_anchor' => $profile->cycle_anchor->value,
                    'funding_method' => $profile->funding_method->value,
                ],
                'modes' => $this->modes->availableModes(),
                'funding_methods' => collect(IncomeMode::cases())
                    ->mapWithKeys(fn (IncomeMode $mode) => [
                        $mode->value => $this->modes->fundingMethodsFor($mode),
                    ])
                    ->all(),
            ],
        ]);
    }

    /**
     * What a switch would change, without changing it.
     */
    public function preview(IncomeModeRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->modes->preview(
                $request->user(),
                IncomeMode::from($request->validated()['income_mode']),
                $request->validated(),
            ),
        ]);
    }

    public function update(IncomeModeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->modes->apply(
            $request->user(),
            IncomeMode::from($validated['income_mode']),
            $validated,
        );

        return response()->json([
            'data' => $result,
            'profile' => new FinancialProfileResource(
                $this->plans->profileFor($request->user()->fresh())
            ),
        ]);
    }
}
