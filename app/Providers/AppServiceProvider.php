<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch missing eager loads and mass-assignment mistakes in development
        // rather than shipping N+1 queries or silently ignored attributes.
        Model::preventLazyLoading($this->app->isLocal());
        Model::preventSilentlyDiscardingAttributes($this->app->isLocal());

        $this->configureRateLimiting();
        $this->configurePasswordReset();
    }

    /**
     * The reset link has to land on the Vue router's reset screen, not a Blade
     * route, so the token is handed to the SPA rather than the server.
     */
    private function configurePasswordReset(): void
    {
        ResetPassword::createUrlUsing(
            fn (User $user, string $token) => url('/reset-password/'.$token).'?'.http_build_query([
                'email' => $user->getEmailForPasswordReset(),
            ])
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));

        // Credential endpoints are limited by both address and account, so one
        // attacker cannot lock out a user by guessing at their email.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(5)->by((string) $request->input('email')),
        ]);

        RateLimiter::for('write', fn (Request $request) => Limit::perMinute(60)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
