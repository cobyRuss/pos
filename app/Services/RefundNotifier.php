<?php

namespace App\Services;

use App\Enums\RefundNotificationStatus;
use App\Models\Refund;
use App\Models\RefundNotification;
use App\Models\Setting;
use App\Support\AuditLogger;
use App\Support\DateFormat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * Tells the owner, on their phone, that cash left the drawer.
 *
 * The store runs unattended by an administrator, so this is the only real-time
 * signal that a refund happened. Two properties matter more than the message
 * itself:
 *
 *  - It never blocks the cashier. Every network call is bounded by a timeout and
 *    every failure is swallowed into the notification row.
 *  - It never fails silently. If Telegram is down, misconfigured, or the queue
 *    worker is not running, the row stays pending or turns failed and the
 *    reconciliation report says so, with a command to retry.
 */
class RefundNotifier
{
    private const API = 'https://api.telegram.org/bot';

    private const TIMEOUT = 5;

    public function __construct(private readonly HttpFactory $http) {}

    /**
     * Deliver the alert, recording the outcome on the refund either way.
     */
    public function send(Refund $refund): RefundNotification
    {
        $notification = $refund->notificationFor(RefundService::ALERT_CHANNEL);

        // Already delivered: a duplicate dispatch must not re-alert the owner.
        if ($notification->isDelivered()) {
            return $notification;
        }

        $token = Setting::telegramBotToken();
        $chatId = Setting::telegramChatId();

        if ($token === null || $chatId === null) {
            return $this->fail(
                $notification,
                'Telegram is not configured. Set the bot token and chat id in Settings > Integrations.',
            );
        }

        try {
            $response = $this->http
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->post(self::API.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $this->message($refund),
                    'parse_mode' => 'HTML',
                ]);

            if (! $response->successful()) {
                return $this->fail($notification, $this->describe($response));
            }
        } catch (ConnectionException $e) {
            return $this->fail($notification, 'Could not reach Telegram: '.$e->getMessage());
        } catch (Throwable $e) {
            return $this->fail($notification, $e->getMessage());
        }

        $notification->forceFill([
            'status' => RefundNotificationStatus::Sent,
            'attempts' => (int) $notification->attempts + 1,
            'error' => null,
            'sent_at' => now(),
        ])->save();

        return $notification;
    }

    /**
     * The alert body: who, which order, why, how much, and whether the owner is
     * expected to look at it.
     */
    private function message(Refund $refund): string
    {
        $refund->loadMissing(['user', 'order']);

        $lines = [
            $refund->review_required
                ? '🔴 <b>HIGH-VALUE REFUND</b>'
                : '💸 <b>Refund issued</b>',
            '',
            '<b>Cashier:</b> '.$this->escape($refund->processed_by),
            '<b>Order:</b> '.$this->escape($refund->order?->order_number ?? 'unknown'),
            '<b>Refund #:</b> '.$this->escape($refund->refund_number),
            '<b>Amount:</b> '.Setting::money($refund->amount),
            '<b>Method:</b> '.$refund->method->label(),
            '<b>Reason:</b> '.$this->escape($refund->reason_code?->label() ?? 'Not recorded'),
        ];

        if ($refund->reason) {
            $lines[] = '<b>Detail:</b> '.$this->escape($refund->reason);
        }

        if ($refund->note) {
            $lines[] = '<b>Note:</b> '.$this->escape($refund->note);
        }

        $lines[] = '<b>Time:</b> '.DateFormat::dateTime($refund->refunded_at);

        if ($refund->review_required) {
            $lines[] = '';
            $lines[] = sprintf(
                'Above your %s review threshold - marked for review in the POS.',
                Setting::money(Setting::refundReviewThreshold()),
            );
        }

        return implode("\n", $lines);
    }

    private function fail(RefundNotification $notification, string $error): RefundNotification
    {
        $notification->forceFill([
            'status' => RefundNotificationStatus::Failed,
            'attempts' => (int) $notification->attempts + 1,
            // Telegram echoes the bot token back in its error bodies, and this
            // string is shown in the POS. Keep only the status and description.
            'error' => mb_substr($error, 0, 1000),
        ])->save();

        AuditLogger::record(
            AuditLogger::REFUND_ALERT_FAILED,
            sprintf('Refund alert for %s could not be delivered: %s', $notification->refund?->refund_number, $error),
        );

        return $notification;
    }

    private function describe(Response $response): string
    {
        $description = $response->json('description');

        return sprintf(
            'Telegram error %d: %s',
            $response->status(),
            is_string($description) ? $description : 'no description',
        );
    }

    /**
     * Telegram parses a small subset of HTML, and refund notes are free text
     * typed by a cashier, so anything that looks like a tag has to be neutralised.
     */
    private function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
