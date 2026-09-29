<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Every date and time the store shows a human, in one place.
 *
 * The formats used to be string literals scattered across forty Blade templates,
 * which had drifted into three conventions - `d/m/Y H:i`, `d M Y`, `M j, Y` -
 * with 12-hour clock times mixed in. That is fine for a demo and wrong for a
 * shop that never closes: an order logged at 21:40 must read the same way at
 * 03:40, and a 24/7 store has no business showing "9:40 PM" next to "9:40 AM"
 * and leaving the reader to work out which is which.
 *
 * So the store has one house style, defined once:
 *
 *   date   mm/dd/yyyy      09/29/2026
 *   time   24-hour         21:40
 *   both   mm/dd/yyyy 24h  09/29/2026 21:40
 *
 * Anything that needs a different granularity asks for it by name rather than
 * reaching for a format string, so the rule stays enforceable in review.
 *
 * Times are the store's own wall clock. `config/app.php` reads `APP_TIMEZONE`
 * (Asia/Manila for this store), so a timestamp rendered here is the time the
 * sale actually happened, not the time it was written to the database.
 */
class DateFormat
{
    /**
     * mm/dd/yyyy
     */
    public const DATE = 'm/d/Y';

    /**
     * 24-hour clock. Deliberately not `g:i A`: a store that trades through the
     * night has no use for an AM/PM marker, and the audit trail reads far
     * faster at 03:12 than at "3:12 AM".
     */
    public const TIME = 'H:i';

    public const DATE_TIME = 'm/d/Y H:i';

    public const DATE_TIME_SECONDS = 'm/d/Y H:i:s';

    /**
     * mm/dd, for chart axes and dense tables where the year is obvious.
     */
    public const DAY_SHORT = 'm/d';

    /**
     * Month and day only, for a column that is always the current year.
     */
    public const MONTH_DAY = 'm/d';

    /**
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function date(null|\DateTimeInterface|string $value): string
    {
        return static::format($value, self::DATE);
    }

    /**
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function time(null|\DateTimeInterface|string $value): string
    {
        return static::format($value, self::TIME);
    }

    /**
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function dateTime(null|\DateTimeInterface|string $value): string
    {
        return static::format($value, self::DATE_TIME);
    }

    /**
     * For a log or an audit trail, where the seconds are the point.
     *
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function dateTimeSeconds(null|\DateTimeInterface|string $value): string
    {
        return static::format($value, self::DATE_TIME_SECONDS);
    }

    /**
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function dayShort(null|\DateTimeInterface|string $value): string
    {
        return static::format($value, self::DAY_SHORT);
    }

    /**
     * A dash for nothing, so a table of timestamps never shows a blank cell that
     * reads as "not recorded" when it means "has not happened yet".
     *
     * @param  \DateTimeInterface|string|null  $value
     */
    public static function format(null|\DateTimeInterface|string $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return Carbon::parse($value)->format($format);
    }
}
