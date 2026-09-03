<?php

namespace App\Console\Commands;

use App\Enums\AdjustmentType;
use App\Models\AuditLog;
use App\Models\BudgetAdjustment;
use App\Services\FinancialPlanService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Repair for overspend adjustments made before the credit half existed.
 *
 * "Take it from next week" and "take it from an allowance" both move money:
 * something gives it up, and the overspent week receives it. Only the first
 * half used to happen, so the week stayed over and the money went nowhere.
 *
 * Whether an adjustment was credited is read from the audit trail rather than
 * guessed: the fixed code records `source_week_budget` alongside the change,
 * and the old code never did. Applying a repair writes its own audit entry, so
 * running this twice cannot credit the same week twice.
 */
class RepairWeekTransfers extends Command
{
    protected $signature = 'finance:repair-week-transfers
        {--user= : Only this user}
        {--adjustment= : Repair only this adjustment id}
        {--reverse : Undo the named adjustment instead of completing it}
        {--force : Apply the corrections}';

    protected $description = 'Credit weeks that gave up budget in an adjustment but never received it';

    public function handle(FinancialPlanService $plans): int
    {
        if ($this->option('reverse')) {
            return $this->reverse($plans);
        }

        $adjustments = BudgetAdjustment::query()
            ->whereIn('type', [AdjustmentType::NextWeek->value, AdjustmentType::Category->value])
            ->whereNotNull('source_weekly_budget_id')
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->when($this->option('adjustment'), fn ($q, $id) => $q->whereKey($id))
            ->with(['sourceWeeklyBudget.monthlyPlan'])
            ->orderBy('id')
            ->get();

        $outstanding = [];

        foreach ($adjustments as $adjustment) {
            $verdict = $this->verdictFor($adjustment);

            $this->line(sprintf(
                '#%d %-9s %8s  %s',
                $adjustment->id,
                $adjustment->type->value,
                Money::of($adjustment->amount),
                $verdict,
            ));

            if ($verdict === 'needs crediting') {
                $outstanding[] = $adjustment;
            }
        }

        if ($outstanding === []) {
            $this->newLine();
            $this->info('Nothing to repair.');

            return self::SUCCESS;
        }

        $this->newLine();

        foreach ($outstanding as $adjustment) {
            $week = $adjustment->sourceWeeklyBudget;
            $before = $week->effectiveBudget();
            $after = Money::add($before, $adjustment->amount);

            $this->line(sprintf(
                '%s week %d of plan %d: %s -> %s',
                $this->option('force') ? 'Crediting' : 'Would credit',
                $week->week_number,
                $week->monthly_plan_id,
                $before,
                $after,
            ));

            if (! $this->option('force')) {
                continue;
            }

            $week->forceFill(['adjusted_amount' => $after])->save();

            // A category adjustment also changed what is reserved, so the
            // plan's stored totals have to be re-derived from the rows.
            if ($adjustment->type === AdjustmentType::Category) {
                $plans->recalculate($week->monthlyPlan->fresh());
                $this->line('  … and re-totalled plan '.$week->monthly_plan_id.'.');
            }

            // The marker that stops a second run doing this again.
            AuditLog::create([
                'user_id' => $adjustment->user_id,
                'action' => 'budget.transfer_repaired',
                'auditable_type' => BudgetAdjustment::class,
                'auditable_id' => $adjustment->id,
                'old_values' => ['source_week_budget' => $before],
                'new_values' => ['source_week_budget' => $after],
                'note' => 'Credited a transfer that was never delivered',
            ]);
        }

        $this->newLine();
        $this->info($this->option('force')
            ? 'Credited '.count($outstanding).' week(s).'
            : count($outstanding).' to credit. Re-run with --force to apply.');

        return self::SUCCESS;
    }

    /** Read from the trail what actually happened to this adjustment. */
    private function verdictFor(BudgetAdjustment $adjustment): string
    {
        if ($adjustment->sourceWeeklyBudget === null) {
            return 'skipped — the week it came from is gone';
        }

        if ($this->hasMarker($adjustment, 'budget.transfer_reversed')) {
            return 'reversed — the money went back where it came from';
        }

        if ($this->hasMarker($adjustment, 'budget.transfer_repaired')) {
            return 'already repaired';
        }

        if ($this->creditedWhenApplied($adjustment)) {
            return 'fine — credited at the time';
        }

        return 'needs crediting';
    }

    private function hasMarker(BudgetAdjustment $adjustment, string $action): bool
    {
        return AuditLog::query()
            ->where('action', $action)
            ->where('auditable_type', BudgetAdjustment::class)
            ->where('auditable_id', $adjustment->id)
            ->exists();
    }

    /**
     * Undo an adjustment that should never have been made: the pot or week
     * that gave the money up gets it back, and anything that was credited is
     * taken back out, so the plan ends exactly as it was before.
     */
    private function reverse(FinancialPlanService $plans): int
    {
        $id = $this->option('adjustment');

        if (! $id) {
            $this->error('--reverse needs --adjustment=<id>: say which one to undo.');

            return self::FAILURE;
        }

        $adjustment = BudgetAdjustment::query()
            ->whereIn('type', [AdjustmentType::NextWeek->value, AdjustmentType::Category->value])
            ->with(['sourceWeeklyBudget.monthlyPlan', 'weeklyBudget'])
            ->find($id);

        if ($adjustment === null) {
            $this->error("No next-week or allowance adjustment #{$id}.");

            return self::FAILURE;
        }

        if ($this->hasMarker($adjustment, 'budget.transfer_reversed')) {
            $this->info("#{$id} has already been reversed.");

            return self::SUCCESS;
        }

        $amount = Money::of($adjustment->amount);
        $credited = $this->hasMarker($adjustment, 'budget.transfer_repaired')
            || $this->creditedWhenApplied($adjustment);
        $source = $adjustment->sourceWeeklyBudget;
        $plan = $source?->monthlyPlan ?? $adjustment->weeklyBudget?->monthlyPlan;

        if ($plan === null) {
            $this->error('The plan this adjustment belonged to is gone.');

            return self::FAILURE;
        }

        $verb = $this->option('force') ? 'Reversing' : 'Would reverse';

        if ($adjustment->type === AdjustmentType::Category) {
            $row = $plan->budgetCategories()
                ->where('category_id', $adjustment->category_id)
                ->where('is_allowance', true)
                ->first();

            if ($row === null) {
                $this->error('The allowance this came from no longer exists on the plan.');

                return self::FAILURE;
            }

            $this->line(sprintf(
                '%s #%d: allowance %s -> %s',
                $verb, $adjustment->id, Money::of($row->budget_amount), Money::add($row->budget_amount, $amount),
            ));

            if ($this->option('force')) {
                $row->forceFill(['budget_amount' => Money::add($row->budget_amount, $amount)])->save();
            }
        } else {
            $target = $adjustment->weeklyBudget;
            $this->line(sprintf(
                '%s #%d: week %d %s -> %s',
                $verb, $adjustment->id, $target->week_number, $target->effectiveBudget(),
                Money::add($target->effectiveBudget(), $amount),
            ));

            if ($this->option('force')) {
                $target->forceFill(['adjusted_amount' => Money::add($target->effectiveBudget(), $amount)])->save();
            }
        }

        if ($credited && $source !== null) {
            $this->line(sprintf(
                '  and week %d %s -> %s (taking the credit back)',
                $source->week_number, $source->effectiveBudget(),
                Money::floorAtZero(Money::sub($source->effectiveBudget(), $amount)),
            ));

            if ($this->option('force')) {
                $source->forceFill([
                    'adjusted_amount' => Money::floorAtZero(Money::sub($source->effectiveBudget(), $amount)),
                ])->save();
            }
        }

        if (! $this->option('force')) {
            $this->newLine();
            $this->info('Re-run with --force to apply.');

            return self::SUCCESS;
        }

        $plans->recalculate($plan->fresh());

        AuditLog::create([
            'user_id' => $adjustment->user_id,
            'action' => 'budget.transfer_reversed',
            'auditable_type' => BudgetAdjustment::class,
            'auditable_id' => $adjustment->id,
            'old_values' => ['amount' => $amount],
            'new_values' => null,
            'note' => 'Undid an adjustment at the account holder\'s request',
        ]);

        $this->newLine();
        $this->info("Reversed #{$adjustment->id} and re-totalled plan {$plan->id}.");

        return self::SUCCESS;
    }

    /**
     * The fixed code records the week's budget beside the change it made. Its
     * absence is what identifies an adjustment from before the fix.
     */
    private function creditedWhenApplied(BudgetAdjustment $adjustment): bool
    {
        $action = $adjustment->type === AdjustmentType::Category
            ? 'budget.category_reduced'
            : 'budget.week_adjusted';

        return AuditLog::query()
            ->where('user_id', $adjustment->user_id)
            ->where('action', $action)
            ->whereBetween('created_at', [
                $adjustment->created_at?->copy()->subSeconds(5),
                $adjustment->created_at?->copy()->addSeconds(5),
            ])
            ->where('new_values', 'like', '%source_week_budget%')
            ->exists();
    }
}
