<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\Expense;
use App\Models\PaymentMethod;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\FinancialPlanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The application is multi-tenant: many independent accounts share one
 * database and must never see each other's money.
 */
class MultiUserIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeOn('2026-09-30');
    }

    #[Test]
    public function several_accounts_can_be_registered_and_kept_apart(): void
    {
        foreach (['amara', 'bandara', 'chalani'] as $name) {
            $this->postJson('/api/auth/register', [
                'name' => ucfirst($name),
                'email' => "{$name}@example.com",
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])->assertCreated();

            $this->postJson('/api/auth/logout');
        }

        $this->assertSame(3, User::count());

        // Each account gets its own profile, categories and payment methods.
        foreach (User::with('financialProfile')->get() as $user) {
            $this->assertNotNull($user->financialProfile);
            $this->assertSame(12, $user->categories()->count());
            $this->assertSame(6, $user->paymentMethods()->count());
        }

        // No category or payment method is shared between accounts.
        $this->assertSame(36, \App\Models\Category::count());
        $this->assertSame(18, PaymentMethod::count());
    }

    #[Test]
    public function each_account_sees_only_its_own_records_in_every_list(): void
    {
        [$amara, $bandara] = [$this->populate('Amara'), $this->populate('Bandara')];

        foreach ([
            '/api/expenses' => 'data',
            '/api/debts' => 'data',
            '/api/savings-goals' => 'data',
            '/api/categories' => 'data',
            '/api/payment-methods' => 'data',
            '/api/recurring-transactions' => 'data',
            '/api/monthly-plans' => 'data',
        ] as $endpoint => $key) {
            $mine = $this->actingAs($amara)->getJson($endpoint)->assertOk()->json($key);
            $theirs = $this->actingAs($bandara)->getJson($endpoint)->assertOk()->json($key);

            $myIds = collect($mine)->pluck('id')->all();
            $theirIds = collect($theirs)->pluck('id')->all();

            $this->assertNotEmpty($myIds, "no records returned for {$endpoint}");
            $this->assertEmpty(
                array_intersect($myIds, $theirIds),
                "{$endpoint} leaked records between accounts",
            );
        }
    }

    #[Test]
    public function dashboards_are_calculated_per_account(): void
    {
        $amara = $this->populate('Amara', salary: '280000.00', spend: '5000.00');
        $bandara = $this->populate('Bandara', salary: '120000.00', spend: '1000.00');

        $mine = $this->actingAs($amara)->getJson('/api/dashboard')->assertOk()->json('data');
        $theirs = $this->actingAs($bandara)->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertSame('280000.00', $mine['salary']['expected']);
        $this->assertSame('120000.00', $theirs['salary']['expected']);

        $this->assertSame('5000.00', $mine['month_budget']['spent']);
        $this->assertSame('1000.00', $theirs['month_budget']['spent']);

        $this->assertNotSame($mine['available_to_spend'], $theirs['available_to_spend']);
    }

    #[Test]
    public function one_account_cannot_reach_anothers_records_by_id(): void
    {
        $amara = $this->populate('Amara');
        $bandara = $this->populate('Bandara');

        $theirExpense = $bandara->expenses()->firstOrFail();
        $theirDebt = $bandara->debts()->firstOrFail();
        $theirGoal = $bandara->savingsGoals()->firstOrFail();
        $theirPlan = $bandara->monthlyPlans()->firstOrFail();
        $theirCategory = $bandara->categories()->firstOrFail();
        $theirMethod = $bandara->paymentMethods()->firstOrFail();
        $theirRecurring = $bandara->recurringTransactions()->firstOrFail();
        $theirWeek = $theirPlan->weeklyBudgets()->firstOrFail();

        $this->actingAs($amara);

        foreach ([
            ['GET', "/api/expenses/{$theirExpense->id}"],
            ['PUT', "/api/expenses/{$theirExpense->id}"],
            ['DELETE', "/api/expenses/{$theirExpense->id}"],
            ['GET', "/api/debts/{$theirDebt->id}"],
            ['PUT', "/api/debts/{$theirDebt->id}"],
            ['DELETE', "/api/debts/{$theirDebt->id}"],
            ['GET', "/api/debts/{$theirDebt->id}/payoff"],
            ['GET', "/api/debts/{$theirDebt->id}/payments"],
            ['POST', "/api/debts/{$theirDebt->id}/payments"],
            ['GET', "/api/savings-goals/{$theirGoal->id}"],
            ['PUT', "/api/savings-goals/{$theirGoal->id}"],
            ['DELETE', "/api/savings-goals/{$theirGoal->id}"],
            ['GET', "/api/savings-goals/{$theirGoal->id}/transactions"],
            ['POST', "/api/savings-goals/{$theirGoal->id}/transactions"],
            ['GET', "/api/monthly-plans/{$theirPlan->id}"],
            ['PUT', "/api/monthly-plans/{$theirPlan->id}"],
            ['GET', "/api/monthly-plans/{$theirPlan->id}/summary"],
            ['GET', "/api/monthly-plans/{$theirPlan->id}/weeks"],
            ['PUT', "/api/monthly-plans/{$theirPlan->id}/weeks"],
            ['POST', "/api/monthly-plans/{$theirPlan->id}/income"],
            ['POST', "/api/monthly-plans/{$theirPlan->id}/finalize"],
            ['POST', "/api/monthly-plans/{$theirPlan->id}/reopen"],
            ['GET', "/api/categories/{$theirCategory->id}"],
            ['PUT', "/api/categories/{$theirCategory->id}"],
            ['DELETE', "/api/categories/{$theirCategory->id}"],
            ['GET', "/api/payment-methods/{$theirMethod->id}"],
            ['PUT', "/api/payment-methods/{$theirMethod->id}"],
            ['DELETE', "/api/payment-methods/{$theirMethod->id}"],
            ['GET', "/api/recurring-transactions/{$theirRecurring->id}"],
            ['PUT', "/api/recurring-transactions/{$theirRecurring->id}"],
            ['DELETE', "/api/recurring-transactions/{$theirRecurring->id}"],
            ['GET', "/api/weekly-budgets/{$theirWeek->id}"],
            ['GET', "/api/weekly-budgets/{$theirWeek->id}/review"],
            ['GET', "/api/weekly-budgets/{$theirWeek->id}/adjustment-options"],
            ['POST', "/api/weekly-budgets/{$theirWeek->id}/adjustments"],
        ] as [$method, $uri]) {
            $status = $this->json($method, $uri)->status();

            $this->assertContains(
                $status,
                [403, 404],
                "{$method} {$uri} returned {$status} instead of denying access",
            );
        }
    }

    #[Test]
    public function an_expense_cannot_be_pointed_at_another_accounts_card(): void
    {
        $amara = $this->populate('Amara');
        $bandara = $this->populate('Bandara');

        $theirCard = $bandara->debts()->where('type', 'credit_card')->firstOrFail();
        $balanceBefore = $theirCard->current_balance;

        $this->actingAs($amara)->postJson('/api/expenses', [
            'amount' => '5000.00',
            'category_id' => $this->categoryId($amara, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($amara, 'Cash'),
            'debt_id' => $theirCard->id,
            'expense_date' => '2026-09-20',
        ])->assertStatus(422)->assertJsonValidationErrors('debt_id');

        $this->assertSame($balanceBefore, $theirCard->fresh()->current_balance);
    }

    #[Test]
    public function a_savings_transfer_cannot_target_another_accounts_goal(): void
    {
        $amara = $this->populate('Amara');
        $bandara = $this->populate('Bandara');

        $mine = $amara->savingsGoals()->firstOrFail();
        $theirs = $bandara->savingsGoals()->firstOrFail();
        $before = $theirs->current_amount;

        $this->actingAs($amara)
            ->postJson("/api/savings-goals/{$mine->id}/transactions", [
                'type' => 'transfer',
                'amount' => '1000.00',
                'to_goal_id' => $theirs->id,
            ])
            ->assertStatus(422);

        $this->assertSame($before, $theirs->fresh()->current_amount);
    }

    #[Test]
    public function two_accounts_can_hold_cards_with_the_same_name_independently(): void
    {
        $amara = $this->makeUser();
        $bandara = $this->makeUser();

        foreach ([$amara, $bandara] as $index => $user) {
            $this->actingAs($user)->postJson('/api/debts', [
                'name' => 'HSBC Visa',
                'type' => 'credit_card',
                'current_balance' => $index === 0 ? '100000.00' : '250000.00',
                'planned_payment' => '10000.00',
            ])->assertCreated();
        }

        $amaraCard = $amara->debts()->firstOrFail();
        $bandaraCard = $bandara->debts()->firstOrFail();

        $this->assertNotSame($amaraCard->id, $bandaraCard->id);

        // Each account's card is linked to a method inside that account only.
        foreach ([[$amara, $amaraCard], [$bandara, $bandaraCard]] as [$user, $card]) {
            $method = PaymentMethod::where('debt_id', $card->id)->firstOrFail();
            $this->assertSame($user->id, $method->user_id);
        }

        // Spending on one account's card leaves the other alone.
        $this->actingAs($amara)->postJson('/api/expenses', [
            'amount' => '4000.00',
            'category_id' => $this->categoryId($amara, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($amara, 'HSBC Visa'),
            'expense_date' => '2026-09-20',
        ])->assertCreated();

        $this->assertSame('104000.00', $amaraCard->fresh()->current_balance);
        $this->assertSame('250000.00', $bandaraCard->fresh()->current_balance);
    }

    #[Test]
    public function reports_and_insights_are_scoped_to_the_signed_in_account(): void
    {
        $amara = $this->populate('Amara', salary: '280000.00', spend: '9000.00', mainCard: '120000.00', secondCard: '40000.00');
        $bandara = $this->populate('Bandara', salary: '120000.00', spend: '2000.00', mainCard: '55000.00', secondCard: '5000.00');

        $mine = $this->actingAs($amara)->getJson('/api/reports/spending')->assertOk()->json('data.total');
        $theirs = $this->actingAs($bandara)->getJson('/api/reports/spending')->assertOk()->json('data.total');

        $this->assertSame('9000.00', $mine);
        $this->assertSame('2000.00', $theirs);

        // Each debt report totals only that account's own cards.
        $myDebt = $this->actingAs($amara)->getJson('/api/reports/debt')->json('data.current_total');
        $theirDebt = $this->actingAs($bandara)->getJson('/api/reports/debt')->json('data.current_total');

        $this->assertSame('160000.00', $myDebt);
        $this->assertSame('60000.00', $theirDebt);

        $myCalendar = $this->actingAs($amara)->getJson('/api/calendar?year=2026&month=9')->json('data');
        $theirCalendar = $this->actingAs($bandara)->getJson('/api/calendar?year=2026&month=9')->json('data');

        // Each calendar names only that account's own bills.
        $myNames = collect($myCalendar)->pluck('name');
        $theirNames = collect($theirCalendar)->pluck('name');

        $this->assertTrue($myNames->contains("Amara's gym"));
        $this->assertFalse($myNames->contains("Bandara's gym"));
        $this->assertTrue($theirNames->contains("Bandara's gym"));
        $this->assertFalse($theirNames->contains("Amara's gym"));
    }

    #[Test]
    public function alerts_belong_to_the_account_that_raised_them(): void
    {
        $amara = $this->populate('Amara');
        $bandara = $this->populate('Bandara');

        $mine = $this->actingAs($amara)->getJson('/api/alerts')->assertOk()->json('data');
        $theirs = $this->actingAs($bandara)->getJson('/api/alerts')->assertOk()->json('data');

        $myIds = collect($mine)->pluck('id')->all();
        $theirIds = collect($theirs)->pluck('id')->all();

        $this->assertEmpty(array_intersect($myIds, $theirIds));

        // And one account cannot dismiss another's alert.
        if ($theirIds !== []) {
            $this->actingAs($amara)->deleteJson("/api/alerts/{$theirIds[0]}")->assertForbidden();
        }
    }

    /**
     * An account with a full financial picture: bills, two cards, a goal, a
     * finalised plan and some spending.
     */
    private function populate(
        string $name,
        string $salary = '280000.00',
        string $spend = '3000.00',
        string $mainCard = '120000.00',
        string $secondCard = '40000.00',
    ): User {
        $user = $this->makeUser(['base_salary' => $salary, 'cycle_start_day' => 25]);
        $user->forceFill(['name' => $name])->save();

        $user->recurringTransactions()->create([
            'name' => "{$name}'s gym",
            'amount' => '3000.00',
            'frequency' => 'monthly',
            'due_day' => 26,
            'amount_type' => 'fixed',
            'start_date' => '2026-01-01',
            'active' => true,
        ]);

        foreach ([['Main Card', $mainCard], ['Second Card', $secondCard]] as [$cardName, $balance]) {
            $card = Debt::create([
                'user_id' => $user->id,
                'name' => $cardName,
                'type' => 'credit_card',
                'original_amount' => $balance,
                'current_balance' => $balance,
                'credit_limit' => '300000.00',
                'minimum_payment' => '5000.00',
                'planned_payment' => '20000.00',
                'due_day' => 1,
                'status' => 'active',
            ]);

            app(\App\Services\CardPaymentMethodService::class)->ensureFor($card);
        }

        SavingsGoal::create([
            'user_id' => $user->id,
            'name' => "{$name}'s fund",
            'target_amount' => '300000.00',
            'current_amount' => '50000.00',
            'monthly_target' => '15000.00',
            'allocation_type' => 'fixed',
            'allocation_value' => '15000.00',
            'priority' => 1,
            'status' => 'active',
        ]);

        $planner = app(FinancialPlanService::class);
        $plan = $planner->draftFor($user->fresh(), 2026, 9);
        $planner->recordActualIncome($plan, $salary, applySplit: false);
        $planner->finalize($plan->fresh());

        Expense::create([
            'user_id' => $user->id,
            'category_id' => $this->categoryId($user, 'Food'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'amount' => $spend,
            'expense_date' => '2026-09-26',
        ]);

        return $user->fresh();
    }
}
