<?php

namespace App\Events;

use App\Models\Refund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A refund has committed and the money has moved.
 *
 * Dispatched only after the database transaction closes, so a listener can never
 * announce a refund that was rolled back. The refund carries its own
 * notification row, created before the dispatch, which is what makes a stopped
 * queue worker visible instead of silent.
 */
class RefundProcessed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Refund $refund) {}
}
