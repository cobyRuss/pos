<?php

namespace App\Listeners;

use App\Events\DayClosed;
use App\Models\Setting;
use App\Support\AuditLogger;
use App\Support\DateFormat;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Pushes the end-of-day summary to the owner's phone.
 *
 * Same shape of problem as the refund alert and the same two requirements: it
 * must not make the cashier wait while the drawer is being closed, and it must
 * not fail silently. The second point is why this class returns quietly instead
 * of throwing - the POS must still close the drawer if Telegram is unreachable,
 * or a network outage at 9pm would stop the store trading.
 */
class SendDaySummary implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * A trading-day summary that arrives tomorrow is noise. One attempt.
     */
    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(private readonly HttpFactory $http) {}

    public function handle(DayClosed $event): void
    {
        $token = Setting::telegramBotToken();
        $chatId = Setting::telegramChatId();

        if ($token === null || $chatId === null) {
            // Not an error worth logging: most stores will not have this set up.
            return;
        }

        try {
            $response = $this->http
                ->timeout(5)
                ->acceptJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $this->message($event),
                    'parse_mode' => 'HTML',
                ]);

            if (! $response->successful()) {
                AuditLogger::record(
                    AuditLogger::DAY_SUMMARY_FAILED,
                    'The close-of-day summary could not be sent to Telegram.',
                );
            }
        } catch (ConnectionException|Throwable $e) {
            // Swallowed on purpose: the drawer is closed either way, and a
            // failed summary must not roll the close back.
            report($e);
        }
    }

    /**
     * The day's figures as the owner wants to read them on a phone: what came
     * in, what left again, and whether the drawer agrees with the arithmetic.
     */
    private function message(DayClosed $event): string
    {
        $summary = $event->summary;
        $session = $event->session;

        $lines = [
            '🧾 <b>Drawer closed</b>',
            '',
            '<b>Cashier:</b> '.$this->escape($session->user?->name ?? 'Unknown'),
            '<b>Opened:</b> '.DateFormat::dateTime($session->opened_at),
            '<b>Closed:</b> '.DateFormat::dateTime($session->closed_at),
            '',
            '<b>Opening float:</b> '.Setting::money($session->opening_float),
            '<b>Cash sales:</b> '.Setting::money($summary['cash_sales']),
            '<b>Cash refunds:</b> '.Setting::money($summary['cash_refunds']),
            '<b>Expected in drawer:</b> '.Setting::money($summary['expected_cash']),
        ];

        if ($summary['counted_cash'] !== null) {
            $lines[] = '<b>Counted:</b> '.Setting::money($summary['counted_cash']);
        }

        $variance = $summary['variance'];

        if ($variance !== null) {
            $lines[] = $variance === 0.0
                ? '✅ <b>Drawer balances exactly.</b>'
                : sprintf(
                    '%s <b>Drawer variance: %s</b>',
                    $variance < 0 ? '🔴' : '🟡',
                    Setting::money(abs($variance)).($variance < 0 ? ' short' : ' over'),
                );
        }

        if ($session->variance_reason) {
            $lines[] = '<b>Reason:</b> '.$this->escape($session->variance_reason);
        }

        return implode("\n", $lines);
    }

    private function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
