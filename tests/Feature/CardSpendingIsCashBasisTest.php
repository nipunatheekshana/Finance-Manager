<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\MonthlyPlan;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\BudgetCalculationService;
use App\Services\CardPaymentMethodService;
use App\Services\CycleSurplusService;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The plan runs on a cash basis. Swiping a card moves no cash: the purchase
 * goes onto the card, the card's available credit shrinks by the same amount,
 * and nothing in the plan's pools is touched. The cash leaves when the bill is
 * paid — and that is the debt allocation's job.
 */
class CardSpendingIsCashBasisTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_card_purchase_touches_no_week_no_month_no_allowance_and_no_category_budget(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();
        $budgets = app(BudgetCalculationService::class);

        $this->charge($user, $method, 'Transport', '6000.00');

        $this->assertSame('0.00', $budgets->discretionarySpentBetween($plan, $plan->cycle_start_date, $plan->cycle_end_date));
        $this->assertSame('0.00', $budgets->weeklySummaries($plan)[0]['spent']);
        $this->assertSame('0.00', $budgets->monthlySummary($plan)['spent']);

        // Pure cash basis: the pots and the warnings reserve cash, and none left.
        $this->assertSame('0.00', collect($budgets->allowanceSummaries($plan))->firstWhere('name', 'Transport')['spent']);
        $this->assertSame('0.00', collect($budgets->categorySummaries($plan))->firstWhere('name', 'Transport')['spent']);
    }

    #[Test]
    public function the_balance_rises_and_available_credit_falls_by_exactly_the_same_amount(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $this->charge($user, $method, 'Transport', '6000.00');

        $card->refresh();
        $this->assertSame('106000.00', Money::of($card->current_balance));
        $this->assertSame('194000.00', $card->availableCredit());

        // The invariant the whole model rests on.
        $this->assertSame(
            Money::of($card->credit_limit),
            Money::add($card->current_balance, $card->availableCredit()),
        );
    }

    #[Test]
    public function a_cash_purchase_still_comes_out_of_the_week(): void
    {
        [$user, $plan] = $this->cycleWithCard();

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => '2500.00',
            'expense_date' => '2026-08-28',
        ]);

        $this->assertSame('2500.00', app(BudgetCalculationService::class)->weeklySummaries($plan)[0]['spent']);
    }

    #[Test]
    public function the_preview_speaks_about_the_card_and_never_warns_about_the_week(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $preview = $this->actingAs($user)->postJson('/api/expenses/preview', [
            'amount' => '250000.00',
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $method->id,
        ])->assertOk()->json('data');

        $this->assertFalse($preview['will_exceed_week']);
        $this->assertFalse($preview['needs_decision']);
        $this->assertNotNull($preview['card']);
        $this->assertSame('Visa', $preview['card']['name']);
        $this->assertSame('350000.00', $preview['card']['balance_after']);
        $this->assertSame('0.00', $preview['card']['available_after']);
        // 350,000 on a 300,000 limit: the bank would decline it.
        $this->assertTrue($preview['card']['exceeds_limit']);
        $this->assertSame('50000.00', $preview['card']['over_limit_by']);
    }

    #[Test]
    public function the_card_reports_what_was_charged_this_cycle_against_the_planned_payment(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $this->charge($user, $method, 'Shopping', '30000.00');

        $data = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('data.debts.credit_card');

        $this->assertSame('30000.00', $data['charged_this_cycle']);
        $this->assertSame('15000.00', $data['planned_payment']);
        // Charging more than the plan pays back: the card is growing.
        $this->assertSame('15000.00', $data['net_change']);
        $this->assertTrue($data['is_growing']);
        $this->assertSame('170000.00', $data['available_credit']);
    }

    #[Test]
    public function charging_past_the_planned_payment_raises_a_warning_that_the_card_is_growing(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $this->charge($user, $method, 'Shopping', '30000.00');

        $alerts = $this->actingAs($user)->getJson('/api/dashboard')->json('data.alerts');

        $this->assertContains('credit_card_growing', array_column($alerts, 'type'));
    }

    #[Test]
    public function the_month_end_leftover_says_how_much_of_it_the_card_bill_will_want(): void
    {
        [$user, $plan, $card, $method] = $this->cycleWithCard();

        $this->charge($user, $method, 'Shopping', '30000.00');

        $this->freezeOn('2026-09-26');
        $surplus = app(CycleSurplusService::class)->summarise($plan->fresh());

        // Nothing was spent in cash, so the whole budget is "left over" — but
        // 30,000 of it is spoken for.
        $this->assertSame('30000.00', $surplus['card_charges']);
    }

    private function charge(User $user, PaymentMethod $method, string $category, string $amount): Expense
    {
        return $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => $amount,
            'expense_date' => '2026-08-28',
            'category_id' => $this->categoryId($user, $category),
            'payment_method_id' => $method->id,
        ])->assertCreated()->json('data') ? Expense::query()->latest('id')->first() : null;
    }

    /**
     * A 300,000-limit Visa at 100,000, paying 15,000 a month; Transport is a
     * 10,000 allowance and Shopping has a 20,000 category budget.
     *
     * @return array{0: User, 1: MonthlyPlan, 2: Debt, 3: PaymentMethod}
     */
    private function cycleWithCard(): array
    {
        $this->freezeOn('2026-08-25');

        $user = $this->makeUser(['base_salary' => '200000.00', 'cycle_start_day' => 25]);
        $user->categories()->where('name', 'Transport')->update(['monthly_budget' => '10000.00', 'is_allowance' => true]);
        $user->categories()->where('name', 'Shopping')->update(['monthly_budget' => '20000.00']);

        $card = Debt::create([
            'user_id' => $user->id,
            'name' => 'Visa',
            'type' => 'credit_card',
            'original_amount' => '100000.00',
            'current_balance' => '100000.00',
            'credit_limit' => '300000.00',
            'minimum_payment' => '5000.00',
            'planned_payment' => '15000.00',
            'interest_rate' => '24.00',
            'due_day' => 15,
        ]);
        $method = app(CardPaymentMethodService::class)->ensureFor($card);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 8);
        $planner->recalculate($plan->fresh());
        $planner->finalize($plan->fresh());

        $this->freezeOn('2026-08-28');

        return [$user->fresh(), $plan->fresh(['weeklyBudgets', 'budgetCategories']), $card->fresh(), $method];
    }
}
