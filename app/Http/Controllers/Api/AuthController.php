<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AccountSetupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\PersonalAccessToken;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly AccountSetupService $setup) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create($request->safe()->only('name', 'email', 'password'));

        // Give the account its default categories and payment methods straight
        // away so the expense form is usable before onboarding finishes.
        $this->setup->prepare($user);

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return response()->json([
            'user' => new UserResource($user->load('financialProfile')),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->safe()->only('email', 'password');

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember'))) {
            // Deliberately vague: never reveal whether the address exists.
            throw ValidationException::withMessages([
                'email' => 'Those credentials do not match our records.',
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        $this->setup->prepare($user);

        return response()->json([
            'user' => new UserResource($user->load('financialProfile')),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        // A token-authenticated client gets the token it presented revoked, so
        // signing out actually ends that session rather than just this request.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        // Drop every resolved guard so nothing keeps serving the old user for
        // the rest of the request. forgetGuards() also resets the default
        // driver, so put it back to the guard this request came in on.
        $default = Auth::getDefaultDriver();
        Auth::forgetGuards();
        Auth::shouldUse($default);

        return response()->json(['message' => 'Signed out.']);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()->load('financialProfile')),
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $status = Password::sendResetLink($request->safe()->only('email'));

        // Always the same answer, so this cannot be used to enumerate accounts.
        return response()->json([
            'message' => 'If that address has an account, a reset link is on its way.',
            'status' => $status === Password::RESET_LINK_SENT ? 'sent' : 'queued',
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->safe()->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => 'That reset link is invalid or has expired.',
            ]);
        }

        return response()->json(['message' => 'Your password has been reset.']);
    }
}
