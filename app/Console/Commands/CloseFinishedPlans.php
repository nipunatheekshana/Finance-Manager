<?php

namespace App\Console\Commands;

use App\Enums\PlanStatus;
use App\Models\MonthlyPlan;
use App\Services\BudgetCalculationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CloseFinishedPlans extends Command
{
    protected $signature = 'finance:close-plans';

    protected $description = 'Mark active plans whose salary cycle has ended as completed';

    public function handle(BudgetCalculationService $budgets): int
    {
        $today = CarbonImmutable::today();

        $plans = MonthlyPlan::query()
            ->where('status', PlanStatus::Active->value)
            ->whereDate('cycle_end_date', '<', $today->toDateString())
            ->get();

        foreach ($plans as $plan) {
            // Settle the cached weekly totals before the month is closed, so
            // the review screen does not have to recompute historical figures.
            $budgets->refreshWeeklySpend($plan);

            $plan->forceFill([
                'status' => PlanStatus::Completed->value,
                'completed_at' => now(),
            ])->save();
        }

        $this->info('Closed '.$plans->count().' finished '.($plans->count() === 1 ? 'plan' : 'plans').'.');

        return self::SUCCESS;
    }
}
