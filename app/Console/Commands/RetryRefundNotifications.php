<?php

namespace App\Console\Commands;

use App\Models\RefundNotification;
use App\Services\RefundNotifier;
use Illuminate\Console\Command;

/**
 * Re-sends refund alerts that never made it to the owner's phone.
 *
 * A refund that completes but whose alert silently fails is the one failure this
 * whole feature cannot afford, so the recovery is a command an admin can run
 * without touching the queue, and it reports what it did rather than failing
 * quietly.
 */
class RetryRefundNotifications extends Command
{
    protected $signature = 'refund:retry-notifications
                            {--pending : Also resend alerts still sitting in the queue}
                            {--limit=100 : Maximum notifications to attempt}';

    protected $description = 'Retry refund alerts that failed or were never delivered';

    public function handle(RefundNotifier $notifier): int
    {
        $query = RefundNotification::query()
            ->with('refund')
            ->whereIn('status', $this->option('pending') ? ['pending', 'failed'] : ['failed']);

        $notifications = $query
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($notifications->isEmpty()) {
            $this->components->info('No refund alerts need retrying.');

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($notifications as $notification) {
            if ($notification->refund === null) {
                $notification->delete();

                continue;
            }

            $result = $notifier->send($notification->refund);

            if ($result->isDelivered()) {
                $sent++;
                $this->components->twoColumnDetail(
                    (string) $notification->refund->refund_number,
                    '<fg=green>sent</>',
                );
            } else {
                $failed++;
                $this->components->twoColumnDetail(
                    (string) $notification->refund->refund_number,
                    '<fg=red>'.$result->status->label().'</>',
                );
            }
        }

        $this->newLine();
        $this->components->info(sprintf('%d sent, %d still failing.', $sent, $failed));

        if ($failed > 0) {
            $this->components->warn('Check the bot token and chat id under Settings > Integrations.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
