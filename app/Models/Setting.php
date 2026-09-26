<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    public const CACHE_KEY = 'pos.settings';

    public const TAX_RATE = 'tax_rate';

    public const CURRENCY_SYMBOL = 'currency_symbol';

    public const BUSINESS_NAME = 'business_name';

    public const BUSINESS_ADDRESS = 'business_address';

    public const BUSINESS_PHONE = 'business_phone';

    public const RECEIPT_FOOTER = 'receipt_footer';

    /**
     * Shown on receipts before an admin has configured the shop's details.
     */
    public const FALLBACK_CURRENCY = '$';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::allCached()[$key] ?? $default;
    }

    /**
     * Percentage of the discounted subtotal added to every sale.
     */
    public static function taxRate(): float
    {
        return (float) static::get(self::TAX_RATE, 0);
    }

    public static function currency(): string
    {
        $symbol = static::get(self::CURRENCY_SYMBOL);

        return blank($symbol) ? self::FALLBACK_CURRENCY : (string) $symbol;
    }

    /**
     * @return array<string, mixed>
     */
    public static function allCached(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return static::query()->pluck('value', 'key')->all();
        });
    }

    public static function put(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => is_array($value) ? json_encode($value) : (string) $value],
        );

        static::flushCache();
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
