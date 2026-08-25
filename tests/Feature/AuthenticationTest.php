<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_visitor_can_register_and_is_signed_in(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Nimal',
            'email' => 'nimal@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $response->assertCreated()->assertJsonPath('user.email', 'nimal@example.com');

        $this->assertDatabaseHas('users', ['email' => 'nimal@example.com']);
        $this->assertAuthenticated();
    }

    #[Test]
    public function registering_creates_the_profile_categories_and_payment_methods(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nimal',
            'email' => 'nimal@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertCreated();

        $user = User::firstWhere('email', 'nimal@example.com');

        $this->assertNotNull($user->financialProfile);
        $this->assertSame(12, $user->categories()->count());
        $this->assertSame(6, $user->paymentMethods()->count());
    }

    #[Test]
    public function registration_rejects_a_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'name' => 'Nimal',
            'email' => 'taken@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    #[Test]
    public function registration_requires_a_confirmed_password_of_at_least_eight_characters(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Nimal',
            'email' => 'nimal@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    #[Test]
    public function a_user_can_sign_in_with_the_right_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'nimal@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'nimal@example.com',
            'password' => 'correct-horse-battery',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    #[Test]
    public function signing_in_with_a_wrong_password_fails_without_revealing_why(): void
    {
        User::factory()->create([
            'email' => 'nimal@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'nimal@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');

        // The message must not distinguish a bad password from a missing account.
        $this->assertSame(
            'Those credentials do not match our records.',
            $response->json('errors.email.0'),
        );
        $this->assertGuest();
    }

    #[Test]
    public function signing_in_with_an_unknown_email_gives_the_same_answer(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery',
        ]);

        $this->assertSame(
            'Those credentials do not match our records.',
            $response->json('errors.email.0'),
        );
    }

    #[Test]
    public function a_user_can_sign_out(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/auth/logout')->assertOk();

        $this->assertGuest();
    }

    #[Test]
    public function the_current_user_endpoint_returns_the_signed_in_account(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);
    }

    #[Test]
    public function the_password_hash_is_never_returned(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/auth/user');

        $this->assertArrayNotHasKey('password', $response->json('user'));
    }

    #[Test]
    public function a_password_reset_request_never_reveals_whether_the_account_exists(): void
    {
        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com']);

        User::factory()->create(['email' => 'real@example.com']);
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'real@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }

    #[Test]
    public function a_user_can_change_their_password_with_the_current_one(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => Hash::make('old-password-here')])->save();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'old-password-here',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    #[Test]
    public function changing_a_password_requires_the_current_one(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['password' => Hash::make('old-password-here')])->save();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'not-the-password',
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');
    }
}
