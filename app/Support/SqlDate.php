<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Driver-aware date expressions.
 *
 * The app runs on MySQL in production, but the test suite uses SQLite, which
 * has no DATE_FORMAT() and no uppercase DATE() function. These helpers emit the
 * right expression for the active connection so the same queries work on both.
 */
class SqlDate
{
    /**
     * Bucket expression built from a MySQL DATE_FORMAT() pattern.
     *
     * The patterns this app uses (%H:00, %d, %Y-%m, %Y-%m-%d) are also valid
     * SQLite strftime() patterns, so the format string passes through as-is.
     */
    public static function bucket(string $format, string $column = 'created_at'): string
    {
        if (self::isSqlite()) {
            return sprintf("strftime('%s', %s)", $format, $column);
        }

        return sprintf("DATE_FORMAT(%s, '%s')", $column, $format);
    }

    /**
     * Expression returning the calendar date (YYYY-MM-DD) of a column.
     */
    public static function day(string $column = 'created_at'): string
    {
        return sprintf('%s(%s)', self::isSqlite() ? 'date' : 'DATE', $column);
    }

    private static function isSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }
}
