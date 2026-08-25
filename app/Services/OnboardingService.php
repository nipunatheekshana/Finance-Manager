<?php

namespace App\Services;

use App\Enums\Frequency;
use App\Models\Category;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns the onboarding wizard's answers into real records in one transaction,
 * so a failure part-way through never leaves a half-configured account.
 */
class OnboardingService
{
    public function __construct(
        private readonly AccountSetupService $setup,
        private readonly CardPaymentMethodService $cards,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function complete(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $this->setup->prepare($user);

            $this->saveProfile($user, $data);
            $this->saveRecurring($user, $data['recurring'] ?? []);
            $this->saveDebts($user, $data['debts'] ?? []);
            $this->saveSavingsGoals($user, $data['savings_goals'] ?? []);

            $user->financialProfile->forceFill([
                'onboarding_completed_at' => now(),
            ])->save();

            return $user->fresh(['financialProfile']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveProfile(User $user, array $data): void
    {
        $user->financialProfile->forceFill([
            'base_salary' => Money::of($data['base_salary']),
            'salary_day' => (int) $data['salary_day'],
            'has_extra_income' => (bool) ($data['has_extra_income'] ?? false),
            'default_buffer' => Money::of($data['default_buffer'] ?? 0),
        ])->save();

        // A salary income source so the figure has somewhere to belong.
        $user->incomeSources()->firstOrCreate(
            ['name' => 'Salary'],
            ['type' => 'salary', 'expected_amount' => Money::of($data['base_salary']), 'active' => true],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveRecurring(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $category = $this->resolveCategory($user, $row['category_name'] ?? null);

            $frequency = Frequency::from($row['frequency']);
            $hasRange = isset($row['minimum_amount']) || isset($row['maximum_amount']);

            $user->recurringTransactions()->create([
                'name' => $row['name'],
                'amount' => Money::of($row['amount']),
                'minimum_amount' => isset($row['minimum_amount']) ? Money::of($row['minimum_amount']) : null,
                'maximum_amount' => isset($row['maximum_amount']) ? Money::of($row['maximum_amount']) : null,
                'amount_type' => $hasRange ? 'range' : 'fixed',
                'category_id' => $category?->id,
                'payment_method_id' => null,
                'frequency' => $frequency->value,
                'due_day' => $row['due_day'] ?? null,
                'day_of_week' => $row['day_of_week'] ?? null,
                'interval_days' => $row['interval_days'] ?? null,
                'start_date' => CarbonImmutable::today()->startOfMonth()->toDateString(),
                'active' => true,
            ]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveDebts(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $balance = Money::of($row['current_balance']);

            $debt = $user->debts()->create([
                'name' => $row['name'],
                'type' => $row['type'],
                'current_balance' => $balance,
                // Progress is measured against wherever the user starts.
                'original_amount' => Money::of($row['original_amount'] ?? $balance),
                'credit_limit' => isset($row['credit_limit']) ? Money::of($row['credit_limit']) : null,
                'interest_rate' => $row['interest_rate'] ?? null,
                'minimum_payment' => Money::of($row['minimum_payment'] ?? 0),
                'planned_payment' => Money::of($row['planned_payment'] ?? 0),
                'due_day' => $row['due_day'] ?? null,
                'remaining_installments' => $row['remaining_installments'] ?? null,
                'installment_amount' => isset($row['planned_payment']) ? Money::of($row['planned_payment']) : null,
                'status' => 'active',
            ]);

            // Every card gets its own payment method, so spending is charged
            // to the specific card it went on.
            $this->cards->ensureFor($debt);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveSavingsGoals(User $user, array $rows): void
    {
        foreach ($rows as $row) {
            $user->savingsGoals()->create([
                'name' => $row['name'],
                'target_amount' => Money::of($row['target_amount']),
                'current_amount' => Money::of($row['current_amount'] ?? 0),
                'monthly_target' => Money::of($row['monthly_target'] ?? 0),
                'allocation_type' => $row['allocation_type'] ?? 'fixed',
                'allocation_value' => Money::of($row['allocation_value'] ?? $row['monthly_target'] ?? 0),
                'target_date' => $row['target_date'] ?? null,
                'priority' => $row['priority'] ?? 3,
                'status' => 'active',
            ]);
        }
    }

    private function resolveCategory(User $user, ?string $name): ?Category
    {
        if ($name === null || $name === '') {
            return null;
        }

        return $user->categories()->firstOrCreate(
            ['name' => $name],
            ['icon' => 'circle', 'color' => 'slate', 'active' => true],
        );
    }
}
