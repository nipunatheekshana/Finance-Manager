<?php

namespace Database\Seeders;

use App\Enums\IncomeMode;
use App\Enums\IncomeSourceKind;
use App\Models\Debt;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\AccountSetupService;
use App\Services\ExpenseService;
use App\Services\FinancialPlanService;
use App\Services\IncomeModeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A freelancer, to sit alongside the salaried demo.
 *
 * Deliberately lumpy: a strong month, a thin one, an unpaid invoice. That is
 * the shape the draw and runway features exist to handle.
 */
class FreelanceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'freelance@financemanager.test'],
            ['name' => 'Freelance Demo', 'password' => Hash::make('password')],
        );

        app(AccountSetupService::class)->prepare($user);
        $user->refresh();

        // Pays themselves LKR 180,000 a month out of whatever comes in.
        app(IncomeModeService::class)->apply($user, IncomeMode::SelfEmployed, [
            'target_draw' => '180000.00',
            'forecast_factor' => 80,
        ]);

        $user->financialProfile->forceFill([
            'default_buffer' => '25000.00',
            'onboarding_completed_at' => now(),
        ])->save();

        $user->refresh();
        $clientSource = $user->incomeSources()
            ->where('kind', IncomeSourceKind::Client->value)
            ->first();

        $today = CarbonImmutable::today();
        $thisMonth = $today->startOfMonth();

        // ── Income: strong month, thin month, one unpaid invoice ──────────
        $ledger = [
            ['320000.00', $thisMonth->subMonthsNoOverflow(3)->addDays(8), 'received', 'Website rebuild'],
            ['95000.00',  $thisMonth->subMonthsNoOverflow(2)->addDays(12), 'received', 'Retainer'],
            ['240000.00', $thisMonth->subMonthsNoOverflow(1)->addDays(6), 'received', 'Mobile app phase 1'],
            ['80000.00',  $thisMonth->addDays(4), 'received', 'Retainer'],
        ];

        foreach ($ledger as [$amount, $date, $status, $description]) {
            $user->incomeTransactions()->firstOrCreate(
                ['description' => $description, 'received_date' => $date->toDateString()],
                [
                    'amount' => $amount,
                    'status' => $status,
                    'type' => 'base',
                    'income_source_id' => $clientSource?->id,
                ],
            );
        }

        // Money owed, and money already late — neither is spendable.
        $user->incomeTransactions()->firstOrCreate(
            ['reference' => 'INV-2041'],
            [
                'amount' => '160000.00',
                'status' => 'invoiced',
                'received_date' => null,
                'due_date' => $today->subDays(9)->toDateString(),
                'type' => 'base',
                'description' => 'Mobile app phase 2',
                'income_source_id' => $clientSource?->id,
            ],
        );

        $user->incomeTransactions()->firstOrCreate(
            ['reference' => 'INV-2042'],
            [
                'amount' => '90000.00',
                'status' => 'invoiced',
                'received_date' => null,
                'due_date' => $today->addDays(12)->toDateString(),
                'type' => 'base',
                'description' => 'Brand identity',
                'income_source_id' => $clientSource?->id,
            ],
        );

        // ── The rest of a normal financial life ───────────────────────────
        $categories = $user->categories()->pluck('id', 'name');
        $methods = $user->paymentMethods()->pluck('id', 'name');

        foreach ([
            ['name' => 'Co-working desk', 'amount' => '18000.00', 'category' => 'Bills', 'due_day' => 3],
            ['name' => 'Internet', 'amount' => '9000.00', 'category' => 'Bills', 'due_day' => 8],
            ['name' => 'Health insurance', 'amount' => '12000.00', 'category' => 'Health', 'due_day' => 15],
        ] as $bill) {
            $user->recurringTransactions()->firstOrCreate(
                ['name' => $bill['name']],
                [
                    'amount' => $bill['amount'],
                    'amount_type' => 'fixed',
                    'category_id' => $categories[$bill['category']],
                    'payment_method_id' => $methods['Bank Account'],
                    'frequency' => 'monthly',
                    'due_day' => $bill['due_day'],
                    'start_date' => $today->subMonths(6)->startOfMonth()->toDateString(),
                    'active' => true,
                ],
            );
        }

        Debt::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Equipment loan'],
            [
                'type' => 'installment',
                'original_amount' => '360000.00',
                'current_balance' => '240000.00',
                'minimum_payment' => '30000.00',
                'planned_payment' => '30000.00',
                'installment_amount' => '30000.00',
                'remaining_installments' => 8,
                'due_day' => 5,
                'status' => 'active',
            ],
        );

        SavingsGoal::firstOrCreate(
            ['user_id' => $user->id, 'name' => 'Lean month fund'],
            [
                'icon' => 'shield',
                'target_amount' => '540000.00',
                'current_amount' => '120000.00',
                'monthly_target' => '20000.00',
                'allocation_type' => 'fixed',
                'allocation_value' => '20000.00',
                'priority' => 1,
                'status' => 'active',
            ],
        );

        // ── This month's plan, funded by the draw ─────────────────────────
        $plans = app(FinancialPlanService::class);
        $plan = $plans->draftFor($user->fresh(), $today->year, $today->month);

        if ($plan->isDraft()) {
            $plans->finalize($plan);
        }

        // A little spending so the budgets are not empty.
        if ($user->expenses()->count() === 0) {
            $expenses = app(ExpenseService::class);

            foreach ([
                ['Food', '2400.00', 1], ['Transport', '1800.00', 3],
                ['Food', '3100.00', 5], ['Shopping', '5600.00', 7],
            ] as [$category, $amount, $dayOffset]) {
                $date = $thisMonth->addDays($dayOffset);

                if ($date->gt($today)) {
                    continue;
                }

                $expenses->create($user, [
                    'category_id' => $categories[$category],
                    'payment_method_id' => $methods['Cash'],
                    'amount' => $amount,
                    'expense_date' => $date->toDateString(),
                    'description' => 'Everyday spending',
                ]);
            }
        }

        $this->command?->info('Freelance demo ready: freelance@financemanager.test / password');
    }
}
