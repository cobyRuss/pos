<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::flushCache());
        static::deleted(fn () => self::flushCache());
    }

    public static function flushCache(): void
    {
        Cache::forget('pos.settings');
    }

    /**
     * All settings as a key => value map, cached per request/short window.
     *
     * @return array<string, mixed>
     */
    public static function all_as_array(): array
    {
        return Cache::rememberForever('pos.settings', function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all_as_array()[$key] ?? $default;
    }

    /**
     * @param  array<string, string|null>  $values
     */
    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(
                ['key' => $key],
                ['value' => $value === null ? null : (string) $value, 'group' => $group],
            );
        }

        self::flushCache();
    }

    public static function currency(): string
    {
        return (string) static::get('currency_symbol', '₱');
    }

    public static function taxRate(): float
    {
        return (float) static::get('tax_rate', 0);
    }

    /**
     * Refund above this amount is flagged for the owner to review.
     *
     * Deliberately advisory: the owner is not on the premises, so a refund that
     * waits for a manager is a customer standing at the counter with no answer.
     * Above the threshold the refund still completes instantly - it just gets
     * pinned to the top of the reconciliation report and pushes an alert.
     */
    public static function refundReviewThreshold(): float
    {
        return (float) static::get('refund_review_threshold', 500);
    }

    /**
     * The most one staff member may refund in a calendar day, in pesos.
     *
     * Zero disables the ceiling. This is the backstop for the failure mode the
     * CCTV cannot see: a cashier who returns a few hundred pesos to a friend
     * every day. Small enough to notice in the daily report, low enough not to
     * interrupt a legitimate return of a bulk basket.
     */
    public static function refundDailyLimit(): float
    {
        return (float) static::get('refund_daily_limit', 0);
    }

    /**
     * The Telegram chat that receives refund alerts, if one is configured.
     */
    public static function telegramChatId(): ?string
    {
        $chatId = trim((string) static::get('telegram_chat_id', ''));

        return $chatId === '' ? null : $chatId;
    }

    /**
     * The Telegram bot token used to post refund alerts, if one is configured.
     */
    public static function telegramBotToken(): ?string
    {
        $token = trim((string) static::get('telegram_bot_token', ''));

        return $token === '' ? null : $token;
    }

    /**
     * Format an amount using the configured currency symbol.
     */
    public static function money(float|int|string|null $amount): string
    {
        return static::currency().number_format((float) $amount, 2);
    }
}
