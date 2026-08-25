<?php

namespace Tests\Feature;

use App\Models\Debt;
use App\Models\MonthlyPlan;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The acceptance workflow from the brief, end to end through the HTTP API:
 * register, set up, receive salary, plan, spend, overspend, adjust, pay debt,
 * save, review, and start the next cycle.
 */
class FullWorkflowTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_can_run_a_whole_salary_cycle(): void
    {
        $this->freezeOn('2026-09-25');

        // ── Register ──────────────────────────────────────────────────────
        $this->postJson('/api/auth/register', [
            'name' => 'Demo',
            'email' => 'workflow@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ])->assertCreated();

        $user = User::firstWhere('email', 'workflow@example.com');
        $this->assertNotNull($user);

        // ── Onboarding: salary, bills, debts, savings ─────────────────────
        $this->actingAs($user)->postJson('/api/onboarding', [
            'income_mode' => 'salaried',
            'base_salary' => '280000.00',
            'cycle_start_day' => 25,
            'has_extra_income' => true,
            'default_buffer' => '20000.00',
            'recurring' => [
                ['name' => 'Gym', 'amount' => '3000.00', 'category_name' => 'Gym', 'frequency' => 'monthly', 'due_day' => 26],
                ['name' => 'Koko', 'amount' => '10000.00', 'category_name' => 'Bills', 'frequency' => 'monthly', 'due_day' => 1],
                [
                    'name' => 'SLT', 'amount' => '9000.00', 'minimum_amount' => '8000.00',
                    'maximum_amount' => '10000.00', 'category_name' => 'Bills',
                    'frequency' => 'monthly', 'due_day' => 28,
                ],
            ],
            'debts' => [
                [
                    'name' => 'Credit Card', 'type' => 'credit_card', 'current_balance' => '377000.00',
                    'credit_limit' => '500000.00', 'minimum_payment' => '18850.00',
                    'planned_payment' => '100000.00', 'due_day' => 1,
                ],
                [
                    'name' => 'Lees', 'type' => 'installment', 'current_balance' => '462000.00',
                    'minimum_payment' => '42000.00', 'planned_payment' => '42000.00',
                    'remaining_installments' => 11, 'due_day' => 5,
                ],
            ],
            'savings_goals' => [
                [
                    'name' => 'Emergency Fund', 'target_amount' => '300000.00',
                    'current_amount' => '50000.00', 'monthly_target' => '15000.00',
                ],
            ],
        ])->assertCreated();

        $user->refresh();
        $this->assertTrue($user->financialProfile->hasCompletedOnboarding());
        $this->assertSame(3, $user->recurringTransactions()->count());
        $this->assertSame(2, $user->debts()->count());
        $this->assertSame(1, $user->savingsGoals()->count());

        // A credit card gets wired to the Credit Card payment method.
        $card = $user->debts()->where('name', 'Credit Card')->first();
        $this->assertSame(
            $card->id,
            $user->paymentMethods()->where('name', 'Credit Card')->value('debt_id'),
        );

        // ── Salary day: open the plan ─────────────────────────────────────
        $plan = $this->actingAs($user)->getJson('/api/monthly-plans/current')->assertOk();
        $planId = $plan->json('data.id');

        $this->assertSame('draft', $plan->json('data.status'));
        $this->assertSame('22000.00', $plan->json('summary.fixed_expenses'));

        // ── Enter the actual salary ───────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$planId}/income", [
                'actual_income' => '300000.00',
                'apply_extra_split' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.actual_income', '300000.00')
            ->assertJsonPath('data.extra_income', '20000.00');

        // ── Allocate debt and savings ─────────────────────────────────────
        $lees = $user->debts()->where('name', 'Lees')->first();
        $goal = $user->savingsGoals()->first();

        $summary = $this->actingAs($user)
            ->putJson("/api/monthly-plans/{$planId}/allocations", [
                'debts' => [
                    ['debt_id' => $card->id, 'planned_amount' => '100000.00'],
                    ['debt_id' => $lees->id, 'planned_amount' => '42000.00'],
                ],
                'savings' => [
                    ['savings_goal_id' => $goal->id, 'planned_amount' => '15000.00'],
                ],
            ])
            ->assertOk()
            ->json('summary');

        // 300,000 − 22,000 − 142,000 − 15,000 − 20,000 = 101,000
        $this->assertSame('142000.00', $summary['debt_payment']);
        $this->assertSame('101000.00', $summary['spending_budget']);
        $this->assertFalse($summary['is_over_allocated']);

        // ── Set the weekly budgets ────────────────────────────────────────
        $this->actingAs($user)->putJson("/api/monthly-plans/{$planId}/weeks", [
            'weeks' => [
                ['week_number' => 1, 'budget_amount' => '20000.00'],
                ['week_number' => 2, 'budget_amount' => '27000.00'],
                ['week_number' => 3, 'budget_amount' => '27000.00'],
                ['week_number' => 4, 'budget_amount' => '27000.00'],
            ],
        ])->assertOk();

        // ── Finalise ──────────────────────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/monthly-plans/{$planId}/finalize")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        // ── Daily spending ────────────────────────────────────────────────
        foreach ([['850.00', 'Food'], ['1200.00', 'Transport'], ['3200.00', 'Smoking']] as [$amount, $category]) {
            $this->actingAs($user)->postJson('/api/expenses', [
                'amount' => $amount,
                'category_id' => $this->categoryId($user, $category),
                'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
                'expense_date' => '2026-09-25',
            ])->assertCreated();
        }

        $dashboard = $this->actingAs($user)->getJson('/api/dashboard')->assertOk()->json('data');

        $this->assertTrue($dashboard['has_plan']);
        $this->assertSame('5250.00', $dashboard['today_budget']['spent']);
        $this->assertSame('95750.00', $dashboard['available_to_spend']);
        $this->assertSame(1, $dashboard['week_budget']['week_number']);

        // ── A few days later, overspend week 1 ────────────────────────────
        $this->freezeOn('2026-09-28');

        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '18000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Cash'),
            'expense_date' => '2026-09-28',
        ])->assertCreated();

        $weekId = MonthlyPlan::find($planId)->weeklyBudgets()->where('week_number', 1)->value('id');

        $options = $this->actingAs($user)
            ->getJson("/api/weekly-budgets/{$weekId}/adjustment-options")
            ->assertOk()
            ->json('data');

        $this->assertTrue($options['is_over_budget']);
        $this->assertSame('3250.00', $options['over_by']);

        // ── Choose to reduce next week ────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/weekly-budgets/{$weekId}/adjustments", ['type' => 'next_week'])
            ->assertOk();

        $weekTwo = MonthlyPlan::find($planId)->weeklyBudgets()->where('week_number', 2)->first();
        $this->assertSame('23750.00', $weekTwo->adjusted_amount);

        // ── Pay the credit card ───────────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/debts/{$card->id}/payments", [
                'amount' => '100000.00',
                'payment_date' => '2026-09-25',
            ])
            ->assertCreated()
            ->assertJsonPath('debt.current_balance', '277000.00');

        // ── New card spending puts the balance back up ────────────────────
        $this->actingAs($user)->postJson('/api/expenses', [
            'amount' => '20000.00',
            'category_id' => $this->categoryId($user, 'Shopping'),
            'payment_method_id' => $this->paymentMethodId($user, 'Credit Card'),
            'expense_date' => '2026-09-28',
        ])->assertCreated();

        $this->assertSame('297000.00', $card->fresh()->current_balance);

        // The payoff estimate follows the real balance.
        $payoff = $this->actingAs($user)
            ->getJson("/api/debts/{$card->id}/payoff")
            ->assertOk()
            ->json('data');
        $this->assertTrue($payoff['is_estimate']);
        $this->assertSame(3, $payoff['estimated_months']);

        // ── Pay the installment debt ──────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/debts/{$lees->id}/payments", [
                'amount' => '42000.00',
                'payment_date' => '2026-09-25',
            ])
            ->assertCreated();

        $this->assertSame(10, $lees->fresh()->remaining_installments);

        // ── Put money aside ───────────────────────────────────────────────
        $this->actingAs($user)
            ->postJson("/api/savings-goals/{$goal->id}/transactions", [
                'type' => 'deposit',
                'amount' => '15000.00',
                'transaction_date' => '2026-09-25',
            ])
            ->assertCreated()
            ->assertJsonPath('goal.current_amount', '65000.00');

        // ── Cash flow and reports ─────────────────────────────────────────
        $this->actingAs($user)->getJson('/api/cash-flow')->assertOk()
            ->assertJsonPath('data.plan_label', 'September 2026');

        $this->actingAs($user)->getJson('/api/reports/spending')->assertOk();
        $this->actingAs($user)->getJson('/api/reports/debt')->assertOk();
        $this->actingAs($user)->getJson('/api/reports/savings')->assertOk();
        $this->actingAs($user)->getJson('/api/reports/income-vs-expenses')->assertOk();
        $this->actingAs($user)->getJson('/api/financial-health')->assertOk();

        // ── Month-end review ──────────────────────────────────────────────
        $review = $this->actingAs($user)->getJson('/api/reports/monthly')->assertOk()->json('data');

        $this->assertSame('September 2026', $review['plan']['label']);
        $this->assertSame('300000.00', $review['plan']['income']);
        $this->assertSame('142000.00', $review['plan']['debt_payments']);
        $this->assertSame('15000.00', $review['plan']['savings']);

        // Paid 100,000 principal, then charged 20,000 back on the card.
        $this->assertSame('80000.00', $review['plan']['credit_card_reduction']);

        // ── Complete the month and start the next cycle ───────────────────
        $this->actingAs($user)->postJson("/api/monthly-plans/{$planId}/complete")->assertOk();
        $this->assertSame('completed', MonthlyPlan::find($planId)->status->value);

        $october = $this->actingAs($user)
            ->postJson('/api/monthly-plans', ['year' => 2026, 'month' => 10])
            ->assertCreated();

        $this->assertSame('October 2026', $october->json('data.label'));
        $this->assertSame('draft', $october->json('data.status'));

        // The new plan starts from the debts as they now stand.
        $octoberCardAllocation = collect($october->json('data.debt_allocations'))
            ->firstWhere('debt_id', $card->id);
        $this->assertSame('100000.00', $octoberCardAllocation['planned_amount']);
    }
}
