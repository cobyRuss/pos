<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A per-day counter for consecutively numbered documents.
 *
 * Order numbers used to be random hex, which reads fine but is the wrong shape
 * for a receipt: a tax inspection expects a run of receipts with no unexplained
 * jumps, and a cashier reprinting a receipt needs a number they can quote over
 * the phone. This hands out 000001, 000002, ... within a day.
 *
 * The increment happens under a row lock, so two sales landing in the same
 * second cannot be handed the same number.
 */
class DocumentSequence extends Model
{
    protected $fillable = [
        'scope',
        'period',
        'next_value',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 'date' alone would write '2026-09-29 00:00:00' into a DATE column
            // while lookups compare against '2026-09-29'. On SQLite those are
            // different strings, so the row would never be found and every
            // insert would collide with the unique index. Pinning the format
            // makes the stored value and the queried value identical.
            'period' => 'date:Y-m-d',
            'next_value' => 'integer',
        ];
    }

    /**
     * Take the next number for a scope on a date, without a gap.
     *
     * The row is locked, read, advanced and written inside one transaction, so
     * two sales landing in the same instant cannot be handed the same number.
     * Nested calls join the enclosing transaction through a savepoint, which is
     * what lets a checkout that fails release its number for reuse instead of
     * burning it: no receipt was ever printed, so no receipt number is skipped.
     */
    public static function take(string $scope, ?Carbon $date = null): int
    {
        $date ??= Carbon::now();
        $day = $date->copy()->startOfDay()->toDateString();

        return DB::transaction(function () use ($scope, $day) {
            $sequence = static::query()->firstOrCreate(
                ['scope' => $scope, 'period' => $day],
                ['next_value' => 1],
            );

            $locked = static::query()
                ->whereKey($sequence->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $number = (int) $locked->next_value;

            $locked->next_value = $number + 1;
            $locked->save();

            return $number;
        });
    }

    /**
     * The first number that has not been issued yet for a scope on a date.
     *
     * This is the number the Z-report reconciles against: everything from 1 to
     * (this minus 1) was either sold or given out, and this one has not.
     */
    public static function nextFor(string $scope, ?Carbon $date = null): int
    {
        $date ??= Carbon::now();

        $sequence = static::query()
            ->where('scope', $scope)
            ->whereDate('period', $date->copy()->startOfDay()->toDateString())
            ->first();

        return $sequence ? (int) $sequence->next_value : 1;
    }
}
