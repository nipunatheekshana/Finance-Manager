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
        private readonly AlertService $alerts,
        private readonly FinancialPlanService $plans,
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
        $candidates = $this->allowanceCandidates($plan, $overBy);

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
                'label' => 'Take it from an allowance',
                'description' => $candidates === []
                    ? 'You have no allowance with money left to move.'
                    : 'Move it out of a pot you set aside, into this week.',
                // Only allowances hold money; a warning line frees nothing.
                'available' => Money::isPositive($overBy) && $candidates !== [],
                'amount' => $overBy,
                'candidates' => $candidates,
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
    /**
     * Allowances with money still to give, and how much each can spare.
     *
     * @return list<array<string, mixed>>
     */
    private function allowanceCandidates(MonthlyPlan $plan, string $needed): array
    {
        return collect($this->budgets->allowanceSummaries($plan))
            ->map(fn (array $row) => [
                'category_id' => $row['category_id'],
                'name' => $row['name'],
                'allocated' => $row['allocated'],
                'spent' => $row['spent'],
                'available' => Money::floorAtZero(Money::sub($row['allocated'], $row['spent'])),
            ])
            ->filter(fn (array $row) => Money::gte($row['available'], $needed))
            ->values()
            ->all();
    }

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

        $adjustment = DB::transaction(fn () => match ($type) {
            AdjustmentType::NextWeek => $this->reduceNextWeek($plan, $week, $amount, $payload),
            AdjustmentType::Buffer => $this->useBuffer($plan, $week, $amount, $payload),
            AdjustmentType::Category => $this->reduceCategory($plan, $week, $amount, $payload),
            AdjustmentType::Ignore => $this->recordIgnore($plan, $week, $amount, $payload),
        });

        // The week's figures have just changed, so whatever alert it was
        // carrying may no longer be true. Done here rather than in the
        // controller so every caller leaves the banners consistent.
        $this->alerts->refreshWeek($week->fresh());

        return $adjustment;
    }

    private function reduceNextWeek(MonthlyPlan $plan, WeeklyBudget $week, string $amount, array $payload): BudgetAdjustment
    {
        $target = $this->nextWeek($week);

        if ($target === null) {
            throw new InvalidArgumentException('There is no later week in this plan to reduce.');
        }

        $original = $target->effectiveBudget();

        // A week cannot give away more than it has.
        $moved = Money::min($amount, $original);

        if (! Money::isPositive($moved)) {
            throw new InvalidArgumentException(
                "Week {$target->week_number} has no budget left to move."
            );
        }

        $adjusted = Money::sub($original, $moved);
        $target->forceFill(['adjusted_amount' => $adjusted])->save();

        // The other half of the move. Without it the later week was reduced
        // and nothing arrived: the overspent week stayed over, and the money
        // was given up for nothing.
        $weekBefore = $week->effectiveBudget();
        $week->forceFill(['adjusted_amount' => Money::add($weekBefore, $moved)])->save();

        $this->audit->record(
            $plan->user_id,
            'budget.week_adjusted',
            $target,
            ['budget' => $original, 'source_week_budget' => $weekBefore],
            ['budget' => $adjusted, 'source_week_budget' => $week->effectiveBudget()],
            'Covering an overspend in week '.$week->week_number,
        );

        return BudgetAdjustment::create([
            'user_id' => $plan->user_id,
            'monthly_plan_id' => $plan->id,
            'weekly_budget_id' => $target->id,
            'source_weekly_budget_id' => $week->id,
            'type' => AdjustmentType::NextWeek->value,
            // What actually moved, which is not always what was asked for.
            'amount' => $moved,
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

        // Only an allowance actually holds money. A plain category budget is a
        // warning line: lowering it frees nothing, so "covering" an overspend
        // with one moved no money at all while looking like it had.
        $budgetCategory = BudgetCategory::query()
            ->where('monthly_plan_id', $plan->id)
            ->where('category_id', $category->id)
            ->where('is_allowance', true)
            ->first();

        if ($budgetCategory === null) {
            throw new InvalidArgumentException(
                $category->name.' has no money set aside this cycle, so there is nothing to move. '
                .'Only an allowance can pay for an overspend.'
            );
        }

        $original = Money::of($budgetCategory->budget_amount);

        // Money already spent out of the pot cannot be handed over.
        $spent = $this->budgets->allowanceStateFor($plan, $category->id)['spent'] ?? '0.00';
        $available = Money::floorAtZero(Money::sub($original, $spent));

        if (Money::lt($available, $amount)) {
            throw new InvalidArgumentException(
                $category->name.' only has '.$available.' left to give — the rest is already spent.'
            );
        }

        $adjusted = Money::sub($original, $amount);
        $budgetCategory->forceFill(['budget_amount' => $adjusted])->save();

        // Less reserved means more day-to-day money, so the plan's own totals
        // have to be re-derived before the week is credited.
        $this->plans->recalculate($plan->fresh());

        $weekBefore = $week->effectiveBudget();
        $week->forceFill(['adjusted_amount' => Money::add($weekBefore, $amount)])->save();

        $this->audit->record(
            $plan->user_id,
            'budget.category_reduced',
            $budgetCategory,
            ['budget' => $original, 'source_week_budget' => $weekBefore],
            ['budget' => $adjusted, 'source_week_budget' => $week->effectiveBudget()],
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
