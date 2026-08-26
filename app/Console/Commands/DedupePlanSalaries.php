<?php

namespace App\Console\Commands;

use App\Models\IncomeTransaction;
use Illuminate\Console\Command;

/**
 * Clean-up for accounts that recorded a salary more than once before the
 * planner started updating that row instead of adding another. Each duplicate
 * inflated the income ledger, the holding pot and the cycle averages.
 *
 * Reports by default and deletes nothing without --force.
 */
class DedupePlanSalaries extends Command
{
    protected $signature = 'finance:dedupe-salaries {--user= : Only this user} {--force : Actually delete the duplicates}';

    protected $description = 'Remove duplicate salary records left by recording a plan income twice';

    public function handle(): int
    {
        $groups = IncomeTransaction::query()
            ->whereNotNull('monthly_plan_id')
            ->where('type', 'base')
            ->whereNotNull('description')
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->orderBy('id')
            ->get()
            // Exactly the rows the planner writes: one salary per plan.
            ->groupBy(fn (IncomeTransaction $row) => $row->monthly_plan_id.'|'.$row->description)
            ->filter(fn ($rows) => $rows->count() > 1);

        if ($groups->isEmpty()) {
            $this->info('No duplicate salary records found.');

            return self::SUCCESS;
        }

        $removed = 0;

        foreach ($groups as $rows) {
            // Keep the newest: it is the correction the user last entered.
            $keep = $rows->last();

            foreach ($rows as $row) {
                if ($row->is($keep)) {
                    continue;
                }

                $this->line(sprintf(
                    '%s plan %d: %s (%s) recorded %s',
                    $this->option('force') ? 'Deleting' : 'Would delete',
                    $row->monthly_plan_id,
                    $row->description,
                    $row->amount,
                    $row->created_at?->toDateString() ?? 'unknown',
                ));

                if ($this->option('force')) {
                    $row->delete();
                }

                $removed++;
            }
        }

        $this->newLine();
        $this->info($this->option('force')
            ? "Removed {$removed} duplicate salary records."
            : "{$removed} duplicates found. Re-run with --force to remove them.");

        return self::SUCCESS;
    }
}
