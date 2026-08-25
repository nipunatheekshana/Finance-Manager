<?php

namespace App\Services;

use App\Enums\CycleAnchor;
use App\Models\FinancialProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out the window a budgeting cycle covers.
 *
 * Two anchors are supported. A salaried user's cycle runs from their pay day to
 * the day before the next one, so a plan labelled "September 2026" is the plan
 * funded by September's pay. Someone with irregular income has no pay day, so
 * their cycle is simply the calendar month.
 *
 * Everything downstream — weekly budgets, daily limits, reports — works from
 * these dates and does not care which anchor produced them.
 */
class BudgetCycleService
{
    /**
     * The day a cycle starts, clamped to the length of the month.
     */
    public function cycleStartDate(int $year, int $month, int $day): CarbonImmutable
    {
        $firstOfMonth = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $clamped = min(max($day, 1), $firstOfMonth->daysInMonth);

        return $firstOfMonth->setDay($clamped);
    }

    /**
     * The [start, end] of the cycle for a period, given an explicit anchor.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function cycleForAnchor(int $year, int $month, CycleAnchor $anchor, int $startDay = 1): array
    {
        if ($anchor === CycleAnchor::CalendarMonth) {
            $start = CarbonImmutable::create($year, $month, 1)->startOfDay();

            return [$start, $start->endOfMonth()->startOfDay()];
        }

        $start = $this->cycleStartDate($year, $month, $startDay);
        $nextMonth = $start->addMonthNoOverflow()->startOfMonth();
        $nextStart = $this->cycleStartDate($nextMonth->year, $nextMonth->month, $startDay);

        return [$start, $nextStart->subDay()->startOfDay()];
    }

    /**
     * The cycle for a period, using the account's own settings.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function cycleFor(int $year, int $month, FinancialProfile $profile): array
    {
        return $this->cycleForAnchor(
            $year,
            $month,
            $profile->cycle_anchor,
            $profile->cycle_start_day,
        );
    }

    /**
     * Which period a date belongs to, given an explicit anchor.
     *
     * @return array{year: int, month: int}
     */
    public function periodForAnchor(CarbonInterface $date, CycleAnchor $anchor, int $startDay = 1): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();

        if ($anchor === CycleAnchor::CalendarMonth) {
            return ['year' => $date->year, 'month' => $date->month];
        }

        // Before this month's start day we are still inside last month's cycle.
        if ($date->lt($this->cycleStartDate($date->year, $date->month, $startDay))) {
            $previous = $date->subMonthNoOverflow();

            return ['year' => $previous->year, 'month' => $previous->month];
        }

        return ['year' => $date->year, 'month' => $date->month];
    }

    /**
     * @return array{year: int, month: int}
     */
    public function periodFor(CarbonInterface $date, FinancialProfile $profile): array
    {
        return $this->periodForAnchor($date, $profile->cycle_anchor, $profile->cycle_start_day);
    }

    /**
     * @return array{year: int, month: int}
     */
    public function currentPeriodFor(FinancialProfile $profile, ?CarbonInterface $today = null): array
    {
        return $this->periodFor($today ?? CarbonImmutable::today(), $profile);
    }

    /**
     * The next date a salaried user is paid. Meaningless without a pay-day
     * anchor, so callers should check the mode first.
     */
    public function nextPayDate(FinancialProfile $profile, ?CarbonInterface $today = null): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today ?? CarbonImmutable::today())->startOfDay();
        $thisMonth = $this->cycleStartDate($today->year, $today->month, $profile->cycle_start_day);

        if ($thisMonth->gte($today)) {
            return $thisMonth;
        }

        $next = $today->addMonthNoOverflow();

        return $this->cycleStartDate($next->year, $next->month, $profile->cycle_start_day);
    }

    /**
     * Split a cycle into week windows using real calendar dates.
     *
     * The week count is the cycle length rounded to the nearest whole number of
     * weeks, so a normal 28-31 day cycle produces four weeks of 7-8 days rather
     * than four full weeks plus a short stub.
     *
     * @return list<array{week_number: int, start_date: CarbonImmutable, end_date: CarbonImmutable, days: int}>
     */
    public function weekWindows(CarbonInterface $start, CarbonInterface $end): array
    {
        $start = CarbonImmutable::instance($start)->startOfDay();
        $end = CarbonImmutable::instance($end)->startOfDay();

        $totalDays = $start->diffInDays($end) + 1;
        if ($totalDays < 1) {
            return [];
        }

        $weekCount = max(1, (int) round($totalDays / 7));

        // Spread the days as evenly as possible, longer weeks first.
        $baseDays = intdiv($totalDays, $weekCount);
        $extra = $totalDays % $weekCount;

        $windows = [];
        $cursor = $start;

        for ($i = 0; $i < $weekCount; $i++) {
            $days = $baseDays + ($i < $extra ? 1 : 0);
            $weekEnd = $cursor->addDays($days - 1);

            if ($weekEnd->gt($end)) {
                $weekEnd = $end;
            }

            $windows[] = [
                'week_number' => $i + 1,
                'start_date' => $cursor,
                'end_date' => $weekEnd,
                'days' => $cursor->diffInDays($weekEnd) + 1,
            ];

            $cursor = $weekEnd->addDay();

            if ($cursor->gt($end)) {
                break;
            }
        }

        return $windows;
    }

    /**
     * Days left in a window counting today as remaining, minimum 1 so callers
     * never divide by zero when working out a daily allowance.
     */
    public function remainingDays(CarbonInterface $today, CarbonInterface $end): int
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $end = CarbonImmutable::instance($end)->startOfDay();

        if ($today->gt($end)) {
            return 0;
        }

        return max(1, $today->diffInDays($end) + 1);
    }
}
