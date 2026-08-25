<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialProfileRequest;
use App\Http\Resources\FinancialProfileResource;
use App\Http\Resources\UserResource;
use App\Services\AuditService;
use App\Services\FinancialPlanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(
        private readonly FinancialPlanService $plans,
        private readonly AuditService $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new FinancialProfileResource($this->plans->profileFor($request->user())),
        ]);
    }

    public function update(FinancialProfileRequest $request): JsonResponse
    {
        $profile = $this->plans->profileFor($request->user());

        $profile->fill($request->validated());

        $this->audit->recordChanges(
            $request->user()->id,
            'profile.updated',
            $profile,
            ['base_salary', 'salary_day', 'default_buffer'],
        );

        $profile->save();

        return response()->json(['data' => new FinancialProfileResource($profile)]);
    }

    public function updateAccount(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
        ]);

        $request->user()->update($validated);

        return response()->json([
            'data' => new UserResource($request->user()->load('financialProfile')),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()->min(8)],
        ]);

        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'That password is not correct.',
            ]);
        }

        $request->user()->update(['password' => $validated['password']]);

        return response()->json(['message' => 'Password updated.']);
    }
}
