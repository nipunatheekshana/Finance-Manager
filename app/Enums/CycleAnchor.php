<?php

namespace App\Enums;

/**
 * What decides where a budgeting cycle starts and ends.
 */
enum CycleAnchor: string
{
    /** Pay day to the day before the next pay day. */
    case PayDay = 'pay_day';

    /** The 1st to the last day of the calendar month. */
    case CalendarMonth = 'calendar_month';

    public function label(): string
    {
        return match ($this) {
            self::PayDay => 'From my pay day',
            self::CalendarMonth => 'Calendar month',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
