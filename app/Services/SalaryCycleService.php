<?php

namespace App\Services;

use App\Models\FinancialProfile;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out the salary cycle a monthly plan covers.
 *
 * A plan labelled "September 2026" is the plan funded by September's salary: it
 * runs from the September salary day up to the day before the October salary
 * day. When the salary day is the 1st this collapses to the calendar month.
 *
 * Salary days beyond the length of a short month are clamped to that month's
 * last day (e.g. day 31 becomes 28 in February).
 */
class SalaryCycleService
{
    /**
     * The salary date for a given month, clamped to the month length.
     */
    public function salaryDate(int $year, int $month, int $salaryDay): CarbonImmutable
    {
        $firstOfMonth = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $day = min(max($salaryDay, 1), $firstOfMonth->daysInMonth);

        return $firstOfMonth->setDay($day);
    }

    /**
     * The [start, end] dates of the cycle funded by the salary of $year/$month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function cycleFor(int $year, int $month, int $salaryDay): array
    {
        $start = $this->salaryDate($year, $month, $salaryDay);
        $nextMonth = $start->addMonthNoOverflow()->startOfMonth();
        $nextSalary = $this->salaryDate($nextMonth->year, $nextMonth->month, $salaryDay);

        return [$start, $nextSalary->subDay()->endOfDay()->startOfDay()];
    }

    /**
     * Which plan (year, month) a given date belongs to.
     *
     * @return array{year: int, month: int}
     */
    public function planPeriodFor(CarbonInterface $date, int $salaryDay): array
    {
        $date = CarbonImmutable::instance($date)->startOfDay();
        $thisMonthSalary = $this->salaryDate($date->year, $date->month, $salaryDay);

        // Before this month's salary day we are still living on last month's pay.
        if ($date->lt($thisMonthSalary)) {
            $previous = $date->subMonthNoOverflow();

            return ['year' => $previous->year, 'month' => $previous->month];
        }

        return ['year' => $date->year, 'month' => $date->month];
    }

    public function currentPeriodFor(FinancialProfile $profile, ?CarbonInterface $today = null): array
    {
        return $this->planPeriodFor($today ?? CarbonImmutable::today(), $profile->salary_day);
    }

    /**
     * Split a cycle into week windows using real calendar dates.
     *
     * The week count is the cycle length rounded to the nearest whole number of
     * weeks, so a normal 30/31-day cycle produces four weeks of 7-8 days rather
     * than four full weeks plus a 2-day stub.
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
