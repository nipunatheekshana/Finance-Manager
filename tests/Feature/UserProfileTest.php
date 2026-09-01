<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The account's own page: a unique handle, a picture, and the history of
 * everything it has done.
 */
class UserProfileTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_account_gets_a_handle_without_being_asked(): void
    {
        $user = $this->makeUser();

        $this->assertNotNull($user->handle, 'A handle is derived on sign-up.');
        $this->assertMatchesRegularExpression(User::HANDLE_PATTERN, $user->handle);
    }

    #[Test]
    public function two_accounts_never_share_a_handle(): void
    {
        $first = User::factory()->create(['name' => 'Nipuna Theekshana']);
        $second = User::factory()->create(['name' => 'Nipuna Theekshana']);

        $this->assertNotSame($first->fresh()->handle, $second->fresh()->handle);
    }

    #[Test]
    public function a_handle_can_be_changed_and_is_stored_lowercase(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->putJson('/api/me', ['handle' => 'Nipuna.T'])
            ->assertOk()
            ->assertJsonPath('user.handle', 'nipuna.t');
    }

    #[Test]
    public function a_taken_handle_is_refused(): void
    {
        $taken = $this->makeUser();
        $taken->forceFill(['handle' => 'moneyman'])->save();

        $this->actingAs($this->makeUser())
            ->putJson('/api/me', ['handle' => 'MoneyMan'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('handle');
    }

    #[Test]
    public function reserved_and_malformed_handles_are_refused(): void
    {
        $user = $this->makeUser();

        foreach (['settings', 'ab', 'has space', '_leading', 'trailing_', 'UPPER!'] as $bad) {
            $this->actingAs($user)
                ->putJson('/api/me', ['handle' => $bad])
                ->assertStatus(422);
        }

        $this->actingAs($user)->putJson('/api/me', ['handle' => 'good.handle_1'])->assertOk();
    }

    #[Test]
    public function a_picture_can_be_uploaded_and_replaced(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('me.jpg', 300, 300)])
            ->assertOk();

        $first = $user->fresh()->avatar_path;
        Storage::disk('public')->assertExists($first);

        $this->actingAs($user)
            ->postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->image('new.png', 300, 300)])
            ->assertOk();

        // The old file goes with it, or a shared host fills up with orphans.
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($user->fresh()->avatar_path);
    }

    #[Test]
    public function a_file_that_is_not_an_image_is_refused(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user)
            ->postJson('/api/me/avatar', ['avatar' => UploadedFile::fake()->create('statement.pdf', 100)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('avatar');

        $this->assertNull($user->fresh()->avatar_path);
    }

    #[Test]
    public function the_profile_reports_the_accounts_whole_history(): void
    {
        [$user] = $this->accountWithHistory();

        $data = $this->actingAs($user)->getJson('/api/me')->assertOk()->json('data');

        $this->assertSame(1, $data['lifetime']['cycles_planned']);
        $this->assertSame(2, $data['lifetime']['expenses_logged']);
        $this->assertSame('4500.00', $data['lifetime']['total_spent']);
        $this->assertSame('12000.00', $data['lifetime']['debt_paid']);

        $this->assertCount(1, $data['months']);
        $this->assertSame('12000.00', $data['months'][0]['debt_paid']);

        $this->assertSame('Visa', $data['debts']['items'][0]['name']);
        $this->assertSame('12000.00', $data['debts']['items'][0]['paid_total']);
        $this->assertCount(1, $data['debts']['recent_payments']);
    }

    #[Test]
    public function the_activity_trail_reads_in_plain_language(): void
    {
        [$user] = $this->accountWithHistory();

        $activity = $this->actingAs($user)->getJson('/api/me/activity')->assertOk()->json('data');

        $this->assertGreaterThan(0, $activity['total']);
        $this->assertContains('Recorded a debt payment', array_column($activity['items'], 'label'));
    }

    #[Test]
    public function one_account_cannot_see_another_accounts_history(): void
    {
        [$owner] = $this->accountWithHistory();
        $stranger = $this->makeUser();

        $data = $this->actingAs($stranger)->getJson('/api/me')->assertOk()->json('data');

        $this->assertSame(0, $data['lifetime']['expenses_logged']);
        $this->assertSame([], $data['months']);
        $this->assertSame([], $data['debts']['recent_payments']);
    }

    /** @return array{0: User} */
    private function accountWithHistory(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $debt = Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '100000.00',
            'current_balance' => '100000.00',
            'minimum_payment' => '5000.00',
            'planned_payment' => '12000.00',
            'interest_rate' => '24.00',
            'due_day' => 15,
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        foreach (['2500.00', '2000.00'] as $amount) {
            Expense::create([
                'user_id' => $user->id,
                'category_id' => $this->categoryId($user, 'Shopping'),
                'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
                'amount' => $amount,
                'expense_date' => '2026-08-28',
            ]);
        }

        $this->actingAs($user)->postJson("/api/debts/{$debt->id}/payments", [
            'amount' => '12000.00',
            'payment_date' => '2026-08-28',
        ])->assertCreated();

        return [$user->fresh()];
    }
}
