<?php

namespace App\Services;

use App\Enums\AdjustmentType;
use App\Models\BudgetAdjustment;
use App\Models\BudgetCategory;
use App\Models\Category;
use App\Models\MonthlyPlan;
use App\Models\WeeklyBudget;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Handles what happens after an overspend.
 *
 * Nothing here runs on its own. The app surfaces the options and the user picks
 * one; money is never silently moved between weeks, categories or the buffer.
 * Every applied choice is written to budget_adjustments.
 */
class BudgetAdjustmentService
{
    public function __construct(
        private readonly BudgetCalculationService $budgets,
        private readonly AuditService $audit,
    ) {}

    /**
     * The choices offered for an overspent week, each with its concrete effect
     * so the UI can show exactly what will happen before the user commits.
     *
     * @return array<string, mixed>
     */
    public function optionsFor(WeeklyBudget $week): array
    {
        $summary = $this->budgets->weeklySummary($week);
        $overBy = Money::of($summary['over_by']);
        $plan = $week->monthlyPlan;

        $nextWeek = $this->nextWeek($week);
        $bufferRemaining = $plan->bufferRemaining();

        $options = [
            [
                'type' => AdjustmentType::NextWeek->value,
                'label' => 'Adjust next week',
                'description' => $nextWeek
                    ? "Reduce week {$nextWeek->week_number} to cover the overspend."
                    : 'No later week is left in this plan to reduce.',
                'available' => $nextWeek !== null && Money::isPositive($overBy),
                'amount' => $overBy,
                'target_week_number' => $nextWeek?->week_number,
                'original_amount' => $nextWeek?->effectiveBudget(),
                'resulting_amount' => $nextWeek
                    ? Money::floorAtZero(Money::sub($nextWeek->effectiveBudget(), $overBy))
                    : null,
            ],
            [
                'type' => AdjustmentType::Buffer->value,
                'label' => 'Use buffer',
                'description' => Money::isPositive($bufferRemaining)
                    ? 'Cover the overspend from this month\'s buffer.'
                    : 'No buffer is left this month.',
                'available' => Money::isPositive($bufferRemaining) && Money::isPositive($overBy),
                'amount' => Money::min($overBy, $bufferRemaining),
                'buffer_remaining' => $bufferRemaining,
                'resulting_buffer' => Money::floorAtZero(
                    Money::sub($bufferRemaining, Money::min($overBy, $bufferRemaining))
                ),
            ],
            [
                'type' => AdjustmentType::Category->value,
                'label' => 'Reduce another category',
                'description' => 'Take the difference from a category budget you choose.',
                'available' => Money::isPositive($overBy),
                'amount' => $overBy,
            ],
            [
                'type' => AdjustmentType::Ignore->value,
                'label' => 'Ignore',
                'description' => 'Leave the plan as it is and carry on.',
                'available' => true,
                'amount' => $overBy,
            ],
        ];

        return [
            'week' => $summary,
            'over_by' => $overBy,
            'is_over_budget' => Money::isPositive($overBy),
            'options' => $options,
        ];
    }

    /**
     * Apply the option the user chose.
     *
     * @param  array{amount?: mixed, category_id?: ?int, reason?: ?string}  $payload
     */
    public function apply(WeeklyBudget $week, AdjustmentType $type, array $payload = []): BudgetAdjustment
    {
        $plan = $week->monthlyPlan;
        $summary = $this->budgets->weeklySummary($week);

        $amount = isset($payload['amount'])
            ? Money::of($payload['amount'])
            : Money::of($summary['over_by']);

        if (! Money::isPositive($amount) && $type !== AdjustmentType::Ignore) {
            throw new InvalidArgumentException('There is nothing to adjust.');
        }

        return DB::transaction(fn () => match ($type) {
            AdjustmentType::NextWeek => $this->reduceNextWeek($plan, $week, $amount, $payload),
            AdjustmentType::Buffer => $this->useBuffer($plan, $week, $amount, $payload),
            AdjustmentType::Category => $this->reduceCategory($plan, $week, $amount, $payload),
            AdjustmentType::Ignore => $this->recordIgnore($plan, $week, $amount, $payload),
        });
    }

    private function reduceNextWeek(MonthlyPlan $plan, WeeklyBudget $week, string $amount, array $payload): BudgetAdjustment
    {
        $target = $this->nextWeek($week);

        if ($target === null) {
            throw new InvalidArgumentException('There is no later week in this plan to reduce.');
        }

        $original = $target->effectiveBudget();
        $adjusted = Money::floorAtZero(Money::sub($original, $amount));

        $target->forceFill(['adjusted_amount' => $adjusted])->save();

        $this->audit->record(
            $plan->user_id,
            'budget.week_adjusted',
            $target,
            ['budget' => $original],
            ['budget' => $adjusted],
            'Covering an overspend in week '.$week->week_number,
        );

        return BudgetAdjustment::create([
            'user_id' => $plan->user_id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $target->id,
            'source_weekly_budget_id' => $week->id,
            'type' => AdjustmentType::NextWeek->value,
            'amount' => $amount,
            'original_amount' => $original,
            'adjusted_amount' => $adjusted,
            'reason' => $payload['reason'] ?? 'Overspend in week '.$week->week_number,
        ]);
    }

    private function useBuffer(MonthlyPlan $plan, WeeklyBudget $week, string $amount, array $payload): BudgetAdjustment
    {
        $available = $plan->bufferRemaining();
        $used = Money::min($amount, $available);

        if (! Money::isPositive($used)) {
            throw new InvalidArgumentException('There is no buffer left to use this month.');
        }

        // The buffer tops up the overspent week rather than the whole plan, so
        // the week's own numbers come back into balance.
        $original = $week->effectiveBudget();
        $adjusted = Money::add($original, $used);

        $week->forceFill(['adjusted_amount' => $adjusted])->save();
        $plan->forceFill(['buffer_used' => Money::add($plan->buffer_used, $used)])->save();

        $this->audit->record(
            $plan->user_id,
            'budget.buffer_used',
            $plan,
            ['buffer_used' => Money::sub($plan->buffer_used, $used)],
            ['buffer_used' => Money::of($plan->buffer_used)],
            'Covering an overspend in week '.$week->week_number,
        );

        return BudgetAdjustment::create([
            'user_id' => $plan->user_id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $week->id,
            'source_weekly_budget_id' => $week->id,
            'type' => AdjustmentType::Buffer->value,
            'amount' => $used,
            'original_amount' => $original,
            'adjusted_amount' => $adjusted,
            'reason' => $payload['reason'] ?? 'Buffer used for week '.$week->week_number,
        ]);
    }

    private function reduceCategory(MonthlyPlan $plan, WeeklyBudget $week, string $amount, array $payload): BudgetAdjustment
    {
        $categoryId = $payload['category_id'] ?? null;

        if ($categoryId === null) {
            throw new InvalidArgumentException('Choose which category budget to reduce.');
        }

        $category = Category::query()
            ->where('user_id', $plan->user_id)
            ->findOrFail($categoryId);

        $budgetCategory = BudgetCategory::firstOrCreate(
            ['monthly_plan_id' => $plan->id, 'category_id' => $category->id],
            ['budget_amount' => Money::of($category->monthly_budget ?? 0)],
        );

        $original = Money::of($budgetCategory->budget_amount);
        $adjusted = Money::floorAtZero(Money::sub($original, $amount));

        $budgetCategory->forceFill(['budget_amount' => $adjusted])->save();

        $this->audit->record(
            $plan->user_id,
            'budget.category_reduced',
            $budgetCategory,
            ['budget' => $original],
            ['budget' => $adjusted],
            'Covering an overspend in week '.$week->week_number,
        );

        return BudgetAdjustment::create([
            'user_id' => $plan->user_id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $week->id,
            'source_weekly_budget_id' => $week->id,
            'category_id' => $category->id,
            'type' => AdjustmentType::Category->value,
            'amount' => $amount,
            'original_amount' => $original,
            'adjusted_amount' => $adjusted,
            'reason' => $payload['reason'] ?? 'Reduced '.$category->name.' to cover week '.$week->week_number,
        ]);
    }

    private function recordIgnore(MonthlyPlan $plan, WeeklyBudget $week, string $amount, array $payload): BudgetAdjustment
    {
        // Nothing changes; the decision itself is what gets recorded.
        return BudgetAdjustment::create([
            'user_id' => $plan->user_id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $week->id,
            'source_weekly_budget_id' => $week->id,
            'type' => AdjustmentType::Ignore->value,
            'amount' => $amount,
            'original_amount' => $week->effectiveBudget(),
            'adjusted_amount' => $week->effectiveBudget(),
            'reason' => $payload['reason'] ?? 'Overspend acknowledged',
        ]);
    }

    private function nextWeek(WeeklyBudget $week): ?WeeklyBudget
    {
        return WeeklyBudget::query()
            ->where('monthly_plan_id', $week->monthly_plan_id)
            ->where('week_number', '>', $week->week_number)
            ->orderBy('week_number')
            ->first();
    }
}
