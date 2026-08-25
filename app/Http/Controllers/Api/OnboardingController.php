<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OnboardingRequest;
use App\Http\Resources\UserResource;
use App\Services\FinancialPlanService;
use App\Services\OnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly OnboardingService $onboarding,
        private readonly FinancialPlanService $plans,
    ) {}

    public function status(Request $request): JsonResponse
    {
        $profile = $this->plans->profileFor($request->user());

        return response()->json([
            'data' => [
                'completed' => $profile->hasCompletedOnboarding(),
                'has_salary' => (float) $profile->base_salary > 0,
                'has_recurring' => $request->user()->recurringTransactions()->exists(),
                'has_debts' => $request->user()->debts()->exists(),
                'has_savings_goals' => $request->user()->savingsGoals()->exists(),
            ],
        ]);
    }

    public function store(OnboardingRequest $request): JsonResponse
    {
        $user = $this->onboarding->complete($request->user(), $request->validated());

        return response()->json([
            'user' => new UserResource($user),
            'message' => 'Setup complete.',
        ], 201);
    }

    /**
     * Let the user skip the wizard and configure things from Settings later.
     */
    public function skip(Request $request): JsonResponse
    {
        $profile = $this->plans->profileFor($request->user());
        $profile->forceFill(['onboarding_completed_at' => now()])->save();

        return response()->json(['message' => 'You can finish setting up from Settings.']);
    }
}
