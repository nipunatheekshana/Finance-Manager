<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\User;
use App\Services\DebtPayoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DebtTest extends TestCase
{
    use RefreshDatabase;

    /** Fixed clock so payment and expense dates are never "in the future". */
    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-30');
    }

    #[Test]
    public function recording_a_payment_reduces_the_balance(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user);

        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", [
                'amount' => '100000.00',
                'payment_date' => '2026-09-01',
            ])
            ->assertCreated()
            ->assertJsonPath('debt.current_balance', '277000.00');

        $this->assertSame('277000.00', $card->fresh()->current_balance);
        $this->assertDatabaseHas('debt_payments', [
            'debt_id' => $card->id,
            'amount' => '100000.00',
            'balance_after' => '277000.00',
        ]);
    }

    #[Test]
    public function a_balance_never_goes_below_zero(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '5000.00');

        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", ['amount' => '9000.00'])
            ->assertCreated();

        $this->assertSame('0.00', $card->fresh()->current_balance);
        $this->assertSame('paid_off', $card->fresh()->status);
    }

    #[Test]
    public function paying_an_installment_debt_reduces_the_remaining_count(): void
    {
        $user = $this->makeUser();

        $lees = Debt::create([
            'user_id' => $user->id,
            'name' => 'Lees',
            'type' => 'installment',
            'original_amount' => '504000.00',
            'current_balance' => '462000.00',
            'minimum_payment' => '42000.00',
            'planned_payment' => '42000.00',
            'installment_amount' => '42000.00',
            'remaining_installments' => 11,
            'status' => 'active',
        ]);

        $this->actingAs($user)
            ->postJson("/api/debts/{$lees->id}/payments", ['amount' => '42000.00'])
            ->assertCreated();

        $lees->refresh();
        $this->assertSame(10, $lees->remaining_installments);
        $this->assertSame('420000.00', $lees->current_balance);
    }

    #[Test]
    public function the_scheduled_remaining_is_the_schedule_total_not_a_settlement_figure(): void
    {
        $user = $this->makeUser();

        $lees = Debt::create([
            'user_id' => $user->id,
            'name' => 'Lees',
            'type' => 'installment',
            'original_amount' => '504000.00',
            'current_balance' => '462000.00',
            'minimum_payment' => '42000.00',
            'planned_payment' => '42000.00',
            'installment_amount' => '42000.00',
            'remaining_installments' => 11,
            'early_settlement_amount' => '430000.00',
            'status' => 'active',
        ]);

        // 11 × 42,000 = 462,000 scheduled, but settling early costs less.
        $this->assertSame('462000.00', $lees->scheduledRemaining());

        $this->actingAs($user)
            ->getJson("/api/debts/{$lees->id}")
            ->assertOk()
            ->assertJsonPath('data.scheduled_remaining', '462000.00')
            ->assertJsonPath('data.early_settlement_amount', '430000.00');
    }

    #[Test]
    public function deleting_a_payment_restores_the_balance_and_the_installment(): void
    {
        $user = $this->makeUser();

        $lees = Debt::create([
            'user_id' => $user->id,
            'name' => 'Lees',
            'type' => 'installment',
            'original_amount' => '504000.00',
            'current_balance' => '462000.00',
            'minimum_payment' => '42000.00',
            'planned_payment' => '42000.00',
            'remaining_installments' => 11,
            'status' => 'active',
        ]);

        $payment = $this->actingAs($user)
            ->postJson("/api/debts/{$lees->id}/payments", ['amount' => '42000.00'])
            ->json('data.id');

        $this->actingAs($user)->deleteJson("/api/debt-payments/{$payment}")->assertOk();

        $lees->refresh();
        $this->assertSame('462000.00', $lees->current_balance);
        $this->assertSame(11, $lees->remaining_installments);
    }

    #[Test]
    public function spending_on_a_linked_card_increases_the_balance(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '277000.00');

        // Link the Credit Card payment method to this debt.
        $user->paymentMethods()->where('name', 'Credit Card')->update(['debt_id' => $card->id]);

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Credit Card'),
            'expense_date' => '2026-09-10',
        ])->assertCreated();

        $this->assertSame('282000.00', $card->fresh()->current_balance);
    }

    #[Test]
    public function a_payment_and_new_spending_both_move_the_balance(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '377000.00');
        $user->paymentMethods()->where('name', 'Credit Card')->update(['debt_id' => $card->id]);

        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", ['amount' => '100000.00'])
            ->assertCreated();

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '20000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Credit Card'),
        ])->assertCreated();

        // 377,000 − 100,000 + 20,000 = 297,000
        $this->assertSame('297000.00', $card->fresh()->current_balance);
    }

    #[Test]
    public function deleting_a_card_expense_reverses_the_charge(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '100000.00');
        $user->paymentMethods()->where('name', 'Credit Card')->update(['debt_id' => $card->id]);

        $expense = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Credit Card'),
        ])->json('data.id');

        $this->assertSame('105000.00', $card->fresh()->current_balance);

        $this->actingAs($user)->deleteJson("/api/expenses/{$expense}")->assertOk();

        $this->assertSame('100000.00', $card->fresh()->current_balance);
    }

    #[Test]
    public function editing_a_card_expense_amount_adjusts_the_balance_by_the_difference(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '100000.00');
        $user->paymentMethods()->where('name', 'Credit Card')->update(['debt_id' => $card->id]);

        $expense = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Credit Card'),
        ])->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/expenses/{$expense}", ['amount' => '8000.00'])
            ->assertOk();

        $this->assertSame('108000.00', $card->fresh()->current_balance);
    }

    #[Test]
    public function a_no_interest_payoff_projects_the_expected_schedule(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '377000.00');

        $projection = app(DebtPayoffService::class)
            ->project($card, '100000.00', \Carbon\CarbonImmutable::parse('2026-08-01'));

        $this->assertTrue($projection['will_be_paid_off']);
        $this->assertSame(4, $projection['estimated_months']);
        $this->assertSame('0.00', $projection['estimated_total_interest']);

        // 377k -> 277k -> 177k -> 77k -> 0
        $remaining = array_column($projection['schedule'], 'remaining_balance');
        $this->assertSame(['277000.00', '177000.00', '77000.00', '0.00'], $remaining);

        // The last payment is only what is still owed.
        $this->assertSame('77000.00', $projection['schedule'][3]['payment']);
    }

    #[Test]
    public function a_payoff_with_interest_adds_estimated_interest(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '100000.00');
        $card->update(['interest_rate' => '24.000']);

        $projection = app(DebtPayoffService::class)->project($card, '20000.00');

        $this->assertTrue($projection['has_interest']);
        $this->assertTrue($projection['will_be_paid_off']);
        $this->assertGreaterThan(5, $projection['estimated_months']);
        $this->assertTrue(\App\Support\Money::isPositive($projection['estimated_total_interest']));
    }

    #[Test]
    public function a_payment_below_the_monthly_interest_is_flagged_rather_than_projected(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '400000.00');
        $card->update(['interest_rate' => '36.000']);

        // 36% a year on 400,000 is 12,000 a month; paying 5,000 never clears it.
        $projection = app(DebtPayoffService::class)->project($card, '5000.00');

        $this->assertFalse($projection['will_be_paid_off']);
        $this->assertNull($projection['estimated_months']);
        $this->assertNotNull($projection['warning']);
    }

    #[Test]
    public function every_payoff_result_is_marked_as_an_estimate(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user);

        $projection = app(DebtPayoffService::class)->project($card);

        $this->assertTrue($projection['is_estimate']);
        $this->assertNotEmpty($projection['note']);
    }

    #[Test]
    public function progress_is_measured_against_the_original_amount(): void
    {
        $user = $this->makeUser();
        $card = $this->creditCard($user, '277000.00');
        $card->update(['original_amount' => '377000.00']);

        // 100,000 of 377,000 cleared is 26.53%.
        $this->assertSame(26.53, $card->fresh()->progressPercentage());
    }

    #[Test]
    public function a_user_cannot_touch_another_users_debt(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $card = $this->creditCard($other);

        $this->actingAs($user)->getJson("/api/debts/{$card->id}")->assertForbidden();
        $this->actingAs($user)->putJson("/api/debts/{$card->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->actingAs($user)->deleteJson("/api/debts/{$card->id}")->assertForbidden();
        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", ['amount' => '100.00'])
            ->assertForbidden();
    }

    private function creditCard(User $user, string $balance = '377000.00'): Debt
    {
        return Debt::create([
            'user_id' => $user->id,
            'name' => 'Credit Card',
            'type' => 'credit_card',
            'original_amount' => $balance,
            'current_balance' => $balance,
            'credit_limit' => '500000.00',
            'minimum_payment' => '18850.00',
            'planned_payment' => '100000.00',
            'due_day' => 1,
            'status' => 'active',
        ]);
    }
}
