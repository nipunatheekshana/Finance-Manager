<?php

namespace Tests;

use App\Models\User;
use App\Services\AccountSetupService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The SPA and the API share an origin, so Sanctum only puts a session on
     * the request when it recognises the caller as the frontend. Sending the
     * Origin header makes tests exercise that same stateful path rather than a
     * token-authenticated one.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.url', 'http://localhost'));
    }

    /**
     * A user with the standard categories, payment methods and a profile,
     * matching what a real account has after registration.
     */
    protected function makeUser(array $profile = []): User
    {
        $user = User::factory()->create();

        app(AccountSetupService::class)->prepare($user);
        $user->refresh();

        if ($profile !== []) {
            $user->financialProfile->forceFill($profile)->save();
            $user->refresh();
        }

        return $user;
    }

    /** Freeze the clock so date-sensitive calculations are deterministic. */
    protected function freezeOn(string $date): CarbonImmutable
    {
        $frozen = CarbonImmutable::parse($date)->startOfDay();
        CarbonImmutable::setTestNow($frozen);
        \Carbon\Carbon::setTestNow($frozen);

        return $frozen;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        \Carbon\Carbon::setTestNow();

        parent::tearDown();
    }

    protected function categoryId(User $user, string $name): int
    {
        return (int) $user->categories()->where('name', $name)->value('id');
    }

    protected function paymentMethodId(User $user, string $name): int
    {
        return (int) $user->paymentMethods()->where('name', $name)->value('id');
    }
}
