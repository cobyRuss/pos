<?php

namespace App\Events;

use App\Models\CashSession;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a drawer is counted and closed.
 *
 * This is the one moment in the day the store is guaranteed to be talking to the
 * owner about, so the close-of-day summary is dispatched here rather than on a
 * timer. The owner is not in the shop, and this is how they learn the day
 * happened.
 */
class DayClosed
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly CashSession $session,
        public readonly array $summary,
    ) {}
}
