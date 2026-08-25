<?php

namespace App\Services;

use App\Enums\Frequency;
use App\Models\RecurringTransaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Expands recurring transactions into the concrete dates they fall on.
 *
 * Weekly and daily bills are counted from the actual calendar dates inside the
 * planning window rather than multiplied by a nominal four, so a 30-day cycle
 * that happens to contain five Mondays is planned as five occurrences.
 */
class RecurringTransactionService
{
    /**
     * Every date this recurrence lands on inside [$start, $end].
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(
        RecurringTransaction $recurring,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $start = CarbonImmutable::instance($start)->startOfDay();
        $end = CarbonImmutable::instance($end)->startOfDay();

        // Clip the window to the recurrence's own lifetime.
        $from = $recurring->start_date
            ? CarbonImmutable::instance($recurring->start_date)->startOfDay()->max($start)
            : $start;

        $to = $recurring->end_date
            ? CarbonImmutable::instance($recurring->end_date)->startOfDay()->min($end)
            : $end;

        if ($from->gt($to)) {
            return [];
        }

        return match ($recurring->frequency) {
            Frequency::Daily => $this->dailyDates($from, $to),
            Frequency::Weekly => $this->weeklyDates($recurring, $from, $to),
            Frequency::Monthly => $this->monthlyDates($recurring, $from, $to),
            Frequency::Yearly => $this->yearlyDates($recurring, $from, $to),
            Frequency::Custom => $this->customDates($recurring, $from, $to),
        };
    }

    public function occurrenceCount(
        RecurringTransaction $recurring,
        CarbonInterface $start,
        CarbonInterface $end,
    ): int {
        return count($this->occurrencesBetween($recurring, $start, $end));
    }

    /**
     * Total planned cost of a recurrence across a window, using the expected
     * amount (which for a variable bill sits between its min and max).
     */
    public function plannedAmountBetween(
        RecurringTransaction $recurring,
        CarbonInterface $start,
        CarbonInterface $end,
    ): string {
        $count = $this->occurrenceCount($recurring, $start, $end);

        return Money::mul($recurring->amount, (string) $count);
    }

    /**
     * Expand a set of recurrences into dated planning rows for one cycle.
     *
     * @param  Collection<int, RecurringTransaction>  $recurrings
     * @return list<array{recurring: RecurringTransaction, occurrences: int, dates: list<CarbonImmutable>, first_due: ?CarbonImmutable, amount: string, total: string}>
     */
    public function expandForCycle(
        Collection $recurrings,
        CarbonInterface $start,
        CarbonInterface $end,
    ): array {
        $rows = [];

        foreach ($recurrings as $recurring) {
            $dates = $this->occurrencesBetween($recurring, $start, $end);

            if ($dates === []) {
                continue;
            }

            $rows[] = [
                'recurring' => $recurring,
                'occurrences' => count($dates),
                'dates' => $dates,
                'first_due' => $dates[0],
                'amount' => Money::of($recurring->amount),
                'total' => Money::mul($recurring->amount, (string) count($dates)),
            ];
        }

        return $rows;
    }

    /** @return list<CarbonImmutable> */
    private function dailyDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $dates[] = $d;
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function weeklyDates(RecurringTransaction $recurring, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Fall back to the weekday the recurrence started on.
        $weekday = $recurring->day_of_week
            ?? CarbonImmutable::instance($recurring->start_date)->dayOfWeek;

        $dates = [];

        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            if ($d->dayOfWeek === (int) $weekday) {
                $dates[] = $d;
            }
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function monthlyDates(RecurringTransaction $recurring, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dueDay = $recurring->due_day
            ?? CarbonImmutable::instance($recurring->start_date)->day;

        $dates = [];
        $cursor = $from->startOfMonth();

        while ($cursor->lte($to)) {
            // Clamp to the month length so day 31 still fires in February.
            $day = min($dueDay, $cursor->daysInMonth);
            $due = $cursor->setDay($day);

            if ($due->betweenIncluded($from, $to)) {
                $dates[] = $due;
            }

            $cursor = $cursor->addMonthNoOverflow()->startOfMonth();
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function yearlyDates(RecurringTransaction $recurring, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $anchor = CarbonImmutable::instance($recurring->start_date);
        $dueDay = $recurring->due_day ?? $anchor->day;

        $dates = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $monthStart = CarbonImmutable::create($year, $anchor->month, 1);
            $due = $monthStart->setDay(min($dueDay, $monthStart->daysInMonth));

            if ($due->betweenIncluded($from, $to)) {
                $dates[] = $due;
            }
        }

        return $dates;
    }

    /** @return list<CarbonImmutable> */
    private function customDates(RecurringTransaction $recurring, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $interval = max(1, (int) ($recurring->interval_days ?? 1));
        $anchor = CarbonImmutable::instance($recurring->start_date)->startOfDay();

        // Jump straight to the first occurrence at or after the window start.
        if ($anchor->lt($from)) {
            $elapsed = $anchor->diffInDays($from);
            $steps = (int) ceil($elapsed / $interval);
            $anchor = $anchor->addDays($steps * $interval);
        }

        $dates = [];

        for ($d = $anchor; $d->lte($to); $d = $d->addDays($interval)) {
            $dates[] = $d;
        }

        return $dates;
    }
}
