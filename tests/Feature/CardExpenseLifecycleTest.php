<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\FinancialAlert;
use App\Models\MonthlyPlan;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\CardPaymentMethodService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A purchase can move between a card and cash after the fact, or disappear.
 * Whichever way it goes, the card's balance and the plan's cash figures have
 * to end up exactly where a fresh entry would have put them.
 */
class CardExpenseLifecycleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function moving_a_purchase_from_card_to_cash_restores_the_card_and_charges_the_week(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();
        $expense = $this->charge($user, $method, '30000.00');

        $this->assertSame('130000.00', Money::of($card->fresh()->current_balance));
        $this->assertSame('0.00', $this->weekSpent($plan));

        $this->actingAs($user)->putJson("/api/expenses/{$expense}", [
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertOk();

        // Back where it started on the card; now real cash out of the week.
        $this->assertSame('100000.00', Money::of($card->fresh()->current_balance));
        $this->assertSame('30000.00', $this->weekSpent($plan));
    }

    #[Test]
    public function moving_a_purchase_from_cash_to_card_does_the_reverse(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $expense = $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '30000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertCreated()->json('data.id');

        $this->assertSame('30000.00', $this->weekSpent($plan));

        $this->actingAs($user)->putJson("/api/expenses/{$expense}", [
            'payment_method_id' => $method->id,
        ])->assertOk();

        $this->assertSame('0.00', $this->weekSpent($plan));
        $this->assertSame('130000.00', Money::of($card->fresh()->current_balance));
        $this->assertSame('170000.00', $card->fresh()->availableCredit());
    }

    #[Test]
    public function moving_a_purchase_between_two_cards_moves_the_balance_with_it(): void
    {
        [$user, $plan, $visa, $visaMethod] = $this->cycleWithCard();

        $amex = Debt::create([
            'user_id' => $user->id, 'name' => 'Amex', 'type' => 'credit_card',
            'original_amount' => '50000.00', 'current_balance' => '50000.00', 'credit_limit' => '200000.00',
            'minimum_payment' => '2000.00', 'planned_payment' => '10000.00', 'interest_rate' => '20.00', 'due_day' => 1,
        ]);
        $amexMethod = app(CardPaymentMethodService::class)->ensureFor($amex);

        $expense = $this->charge($user, $visaMethod, '30000.00');

        $this->actingAs($user)->putJson("/api/expenses/{$expense}", [
            'payment_method_id' => $amexMethod->id,
        ])->assertOk();

        $this->assertSame('100000.00', Money::of($visa->fresh()->current_balance));
        $this->assertSame('80000.00', Money::of($amex->fresh()->current_balance));
        $this->assertSame('0.00', $this->weekSpent($plan), 'Still on a card, so still no cash.');
    }

    #[Test]
    public function deleting_a_card_purchase_takes_it_off_the_card_and_leaves_the_plan_alone(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();
        $expense = $this->charge($user, $method, '30000.00');

        $this->actingAs($user)->deleteJson("/api/expenses/{$expense}")->assertOk();

        $this->assertSame('100000.00', Money::of($card->fresh()->current_balance));
        // 300,000 limit less the 100,000 still owed.
        $this->assertSame('200000.00', $card->fresh()->availableCredit());
        $this->assertSame('0.00', $this->weekSpent($plan));
    }

    #[Test]
    public function the_growing_warning_goes_when_the_purchase_that_caused_it_leaves_the_card(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        // 30,000 charged against a 15,000 planned payment: growing.
        $expense = $this->charge($user, $method, '30000.00');
        $this->assertTrue($this->hasGrowingAlert($user, $card));

        // Moved to cash: the card is no longer growing.
        $this->actingAs($user)->putJson("/api/expenses/{$expense}", [
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
        ])->assertOk();

        $this->assertFalse($this->hasGrowingAlert($user, $card), 'The alert has to follow the figures.');
    }

    #[Test]
    public function the_growing_warning_also_goes_when_the_purchase_is_deleted(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $expense = $this->charge($user, $method, '30000.00');
        $this->assertTrue($this->hasGrowingAlert($user, $card));

        $this->actingAs($user)->deleteJson("/api/expenses/{$expense}")->assertOk();

        $this->assertFalse($this->hasGrowingAlert($user, $card));
    }

    private function hasGrowingAlert(User $user, Debt $card): bool
    {
        return FinancialAlert::query()
            ->where('user_id', $user->id)
            ->where('type', 'credit_card_growing')
            ->where('reference', 'card-growing:'.$card->id)
            ->exists();
    }

    private function weekSpent(MonthlyPlan $plan): string
    {
        return app(BudgetCalculationService::class)->weeklySummaries($plan->fresh(['weeklyBudgets']))[0]['spent'];
    }

    private function charge(User $user, PaymentMethod $method, string $amount): int
    {
        return $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => $amount,
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $method->id,
        ])->assertCreated()->json('data.id');
    }

    /** @return array{0: User, 1: MonthlyPlan, 2: Debt, 3: PaymentMethod} */
    private function cycleWithCard(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);

        $card = Debt::create([
            'user_id' => $user->id, 'name' => 'Visa', 'type' => 'credit_card',
            'original_amount' => '100000.00', 'current_balance' => '100000.00', 'credit_limit' => '300000.00',
            'minimum_payment' => '5000.00', 'planned_payment' => '15000.00', 'interest_rate' => '24.00', 'due_day' => 15,
        ]);
        $method = app(CardPaymentMethodService::class)->ensureFor($card);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh(['weeklyBudgets']), $card->fresh(), $method];
    }
}
