<?php

namespace App\Console\Commands;

use App\Enums\AdjustmentType;
use App\Models\BudgetAdjustment;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Repair for overspend adjustments made before the credit half existed: the
 * later week — or the allowance — gave the money up and the overspent week
 * never received it, so it stayed over budget for nothing. A category
 * adjustment also left the plan's stored totals disagreeing with the rows
 * they are derived from, so those are re-derived too.
 *
 * Only weeks that were never adjusted at all are touched, so a week that has
 * since been changed for any other reason is reported and left alone rather
 * than credited twice.
 */
class RepairWeekTransfers extends Command
{
    private \App\Services\FinancialPlanService $plans;

    protected $signature = 'finance:repair-week-transfers {--user= : Only this user} {--force : Apply the corrections}';

    protected $description = 'Credit weeks that gave up budget in a transfer but never received it';

    public function handle(\App\Services\FinancialPlanService $plans): int
    {
        $this->plans = $plans;

        $adjustments = BudgetAdjustment::query()
            // Both kinds moved money out of something and never delivered it.
            ->whereIn('type', [AdjustmentType::NextWeek->value, AdjustmentType::Category->value])
            ->whereNotNull('source_weekly_budget_id')
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->with(['sourceWeeklyBudget', 'weeklyBudget'])
            ->orderBy('id')
            ->get();

        $repaired = 0;
        $skipped = 0;

        foreach ($adjustments as $adjustment) {
            $source = $adjustment->sourceWeeklyBudget;

            if ($source === null) {
                continue;
            }

            // A week that has been adjusted since cannot be repaired blindly:
            // there is no way to tell the credit apart from a later change.
            if ($source->adjusted_amount !== null) {
                $this->line(sprintf(
                    'Skipping week %d of plan %d: already adjusted to %s.',
                    $source->week_number,
                    $source->monthly_plan_id,
                    Money::of($source->adjusted_amount),
                ));
                $skipped++;

                continue;
            }

            $credited = Money::add($source->budget_amount, $adjustment->amount);

            $this->line(sprintf(
                '%s week %d of plan %d: %s -> %s (+%s)',
                $this->option('force') ? 'Crediting' : 'Would credit',
                $source->week_number,
                $source->monthly_plan_id,
                Money::of($source->budget_amount),
                $credited,
                Money::of($adjustment->amount),
            ));

            if ($this->option('force')) {
                $source->forceFill(['adjusted_amount' => $credited])->save();

                // A category adjustment also changed what is reserved, and the
                // plan's stored totals were never re-derived from it.
                if ($adjustment->type === AdjustmentType::Category) {
                    $this->plans->recalculate($source->monthlyPlan->fresh());
                    $this->line('  … and re-totalled plan '.$source->monthly_plan_id.'.');
                }
            }

            $repaired++;
        }

        $this->newLine();

        if ($repaired === 0) {
            $this->info('Nothing to repair.'.($skipped > 0 ? " {$skipped} skipped." : ''));

            return self::SUCCESS;
        }

        $this->info($this->option('force')
            ? "Credited {$repaired} week(s)."
            : "{$repaired} week(s) to credit. Re-run with --force to apply.");

        return self::SUCCESS;
    }
}
