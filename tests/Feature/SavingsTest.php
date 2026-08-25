<?php

namespace Tests\Feature;

use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\SavingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SavingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-30');
    }

    #[Test]
    public function a_deposit_increases_the_goal_balance(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'deposit',
                'amount' => '15000.00',
                'transaction_date' => '2026-09-26',
            ])
            ->assertCreated()
            ->assertJsonPath('goal.current_amount', '65000.00');

        $this->assertSame('65000.00', $goal->fresh()->current_amount);
    }

    #[Test]
    public function a_withdrawal_reduces_the_goal_balance(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'withdrawal',
                'amount' => '20000.00',
            ])
            ->assertCreated();

        $this->assertSame('30000.00', $goal->fresh()->current_amount);
    }

    #[Test]
    public function a_withdrawal_cannot_exceed_what_the_goal_holds(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'withdrawal',
                'amount' => '80000.00',
            ])
            ->assertStatus(422);

        $this->assertSame('50000.00', $goal->fresh()->current_amount);
    }

    #[Test]
    public function money_can_be_transferred_between_two_goals(): void
    {
        $user = $this->makeUser();
        $from = $this->emergencyFund($user);
        $to = SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Holiday',
            'target_amount' => '100000.00',
            'current_amount' => '0.00',
            'monthly_target' => '5000.00',
            'allocation_type' => 'fixed',
            'priority' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$from->id}/transactions", [
                'type' => 'transfer',
                'amount' => '10000.00',
                'to_goal_id' => $to->id,
            ])
            ->assertCreated();

        $this->assertSame('40000.00', $from->fresh()->current_amount);
        $this->assertSame('10000.00', $to->fresh()->current_amount);

        // Both halves of the transfer are recorded.
        $this->assertDatabaseHas('savings_transactions', [
            'savings_goal_id' => $from->id,
            'type' => 'transfer_out',
            'related_goal_id' => $to->id,
        ]);
        $this->assertDatabaseHas('savings_transactions', [
            'savings_goal_id' => $to->id,
            'type' => 'transfer_in',
            'related_goal_id' => $from->id,
        ]);
    }

    #[Test]
    public function a_transfer_needs_a_destination_goal(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'transfer',
                'amount' => '1000.00',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_goal_id');
    }

    #[Test]
    public function a_transfer_cannot_target_another_users_goal(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $goal = $this->emergencyFund($user);
        $theirs = $this->emergencyFund($other);

        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'transfer',
                'amount' => '1000.00',
                'to_goal_id' => $theirs->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_goal_id');
    }

    #[Test]
    public function reaching_the_target_marks_the_goal_as_reached(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        app(SavingsService::class)->deposit($goal, ['amount' => '250000.00']);

        $goal->refresh();
        $this->assertSame('reached', $goal->status);
        $this->assertTrue($goal->isReached());
        $this->assertSame(100.0, $goal->progressPercentage());
    }

    #[Test]
    public function progress_is_reported_as_a_percentage_of_the_target(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        app(SavingsService::class)->deposit($goal, ['amount' => '15000.00']);

        // 65,000 of 300,000 is 21.67%.
        $this->assertSame(21.67, $goal->fresh()->progressPercentage());
        $this->assertSame('235000.00', $goal->fresh()->remainingAmount());
    }

    #[Test]
    public function deleting_a_transaction_undoes_its_effect(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $transaction = $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'deposit',
                'amount' => '15000.00',
            ])
            ->json('data.id');

        $this->actingAs($user)
            ->deleteJson("/api/savings-transactions/{$transaction}")
            ->assertOk();

        $this->assertSame('50000.00', $goal->fresh()->current_amount);
    }

    #[Test]
    public function net_saved_counts_deposits_and_transfers_in_less_withdrawals(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);
        $savings = app(SavingsService::class);

        $savings->deposit($goal, ['amount' => '20000.00', 'transaction_date' => '2026-09-05']);
        $savings->withdraw($goal, ['amount' => '5000.00', 'transaction_date' => '2026-09-10']);

        $net = $savings->netSavedBetween(
            $user->id,
            CarbonImmutable::parse('2026-09-01'),
            CarbonImmutable::parse('2026-09-30'),
        );

        $this->assertSame('15000.00', $net);
    }

    #[Test]
    public function a_goal_with_history_is_archived_rather_than_deleted(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        app(SavingsService::class)->deposit($goal, ['amount' => '1000.00']);

        $this->actingAs($user)->deleteJson("/api/savings-goals/{$goal->id}")->assertOk();

        $this->assertDatabaseHas('savings_goals', ['id' => $goal->id, 'status' => 'archived']);
    }

    #[Test]
    public function a_goal_without_history_is_deleted_outright(): void
    {
        $user = $this->makeUser();
        $goal = $this->emergencyFund($user);

        $this->actingAs($user)->deleteJson("/api/savings-goals/{$goal->id}")->assertOk();

        $this->assertDatabaseMissing('savings_goals', ['id' => $goal->id]);
    }

    #[Test]
    public function a_user_cannot_touch_another_users_savings_goal(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $goal = $this->emergencyFund($other);

        $this->actingAs($user)->getJson("/api/savings-goals/{$goal->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/savings-goals/{$goal->id}", ['name' => 'Mine'])->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", ['type' => 'deposit', 'amount' => '100.00'])
            ->assertForbidden();
    }

    private function emergencyFund(User $user): SavingsGoal
    {
        return SavingsGoal::create([
            'user_id' => $user->id,
            'name' => 'Emergency Fund',
            'target_amount' => '300000.00',
            'current_amount' => '50000.00',
            'monthly_target' => '15000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '15000.00',
            'priority' => 1,
            'status' => 'active',
        ]);
    }
}
