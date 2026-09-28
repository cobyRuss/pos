<?php

namespace App\Listeners;

use App\Events\RefundProcessed;
use App\Services\RefundNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Pushes the refund alert to the owner's phone, off the request cycle.
 *
 * Queued so a slow or unreachable Telegram never makes a customer wait at the
 * counter. The listener itself never rethrows: the outcome, successful or not,
 * is written to the refund's notification row by `RefundNotifier`, and the
 * reconciliation report reads it from there.
 */
class SendRefundAlert implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Give up quickly. A refund alert that arrives two hours late is worse than
     * one that never arrives, because the owner will act on it as if it were
     * current.
     */
    public int $tries = 1;

    public int $timeout = 20;

    /**
     * Laravel passes a queued listener only the event, so the notifier is pulled
     * out of the container here rather than type-hinted. That is also the point:
     * the Telegram settings have to be read fresh, because a token rotated after
     * the job was queued should not be the token used to send it.
     */
    public function handle(RefundProcessed $event): void
    {
        app(RefundNotifier::class)->send($event->refund);
    }

    /**
     * Laravel hands a queued listener's `failed()` hook the event first and the
     * exception second.
     *
     * The alert names a specific act by a specific person at a specific time.
     * Replaying it later does not make it more true, so the queue's own retries
     * are pointless; the `refund:retry-notifications` command is the real
     * recovery path and it is visible in the UI.
     */
    public function failed(RefundProcessed $event, ?Throwable $exception): void
    {
        if ($exception) {
            report($exception);
        }
    }
}
