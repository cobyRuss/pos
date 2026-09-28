<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One delivery of a product, carrying its own expiration date.
 *
 * A lot is a quantity of the same product that arrived together, so its
 * expiration date is known exactly. `products.stock` is the cached sum of every
 * lot, maintained by InventoryService - see Product::recalculateStock().
 *
 * A lot with a null expiry_date never expires, which is how non-perishables and
 * the migrated opening balances are represented.
 */
class ProductBatch extends Model
{
    /**
     * Default lead time for the "expiring soon" warning.
     */
    public const EXPIRY_WARNING_DAYS = 30;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'batch_no',
        'expiry_date',
        'quantity',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }

    /**
     * The column is a DATE, so normalise on the way in.
     *
     * Eloquent's `date` cast would happily write a full "Y-m-d H:i:s" into a
     * DATE column, which then no longer matches a plain "Y-m-d" comparison
     * against a date input.
     */
    protected function expiryDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn (mixed $value) => filled($value)
                ? Carbon::parse($value)->toDateString()
                : null,
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * Lots that still hold units.
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }

    /**
     * Lots holding units whose expiry date has already passed.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->inStock()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<', now());
    }

    /**
     * Sellable lots: in stock and not yet expired. FEFO draws from this.
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->inStock()
            ->where(function (Builder $q) {
                $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', now());
            });
    }

    /**
     * Lots expiring within the given number of days, excluding the expired ones.
     */
    public function scopeExpiringWithin(Builder $query, int $days = self::EXPIRY_WARNING_DAYS): Builder
    {
        return $query->inStock()
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '>=', now())
            ->whereDate('expiry_date', '<=', now()->addDays($days));
    }

    /**
     * First-expiry-first-out ordering: soonest date first, undated lots last
     * because they never expire and can wait.
     */
    public function scopeByExpiry(Builder $query): Builder
    {
        return $query->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id');
    }

    public function hasExpiryDate(): bool
    {
        return $this->expiry_date !== null;
    }

    /**
     * Whether the lot's date has passed. A lot is good *through* its expiry
     * date, so only yesterday and earlier count as expired.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null
            && $this->expiry_date->startOfDay()->isBefore(now()->startOfDay());
    }

    public function isExpiringSoon(int $days = self::EXPIRY_WARNING_DAYS): bool
    {
        if ($this->expiry_date === null || $this->isExpired()) {
            return false;
        }

        return $this->expiry_date->startOfDay()->lessThanOrEqualTo(now()->addDays($days)->startOfDay());
    }

    /**
     * Whole days until expiry. Negative once past, null when undated.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false);
    }

    /**
     * One of: none, ok, expiring, expired - for badge classes and filters.
     */
    public function status(): string
    {
        if (! $this->hasExpiryDate()) {
            return 'none';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return $this->isExpiringSoon() ? 'expiring' : 'ok';
    }

    /**
     * Human label for the expiry column, e.g. "Expired 3 days ago",
     * "Expires in 12 days" or "No expiry".
     */
    public function expiryLabel(): string
    {
        if (! $this->hasExpiryDate()) {
            return 'No expiry';
        }

        $days = (int) $this->daysUntilExpiry();

        if ($days < 0) {
            $ago = abs($days);

            return $ago === 1 ? 'Expired yesterday' : sprintf('Expired %d days ago', $ago);
        }

        if ($days === 0) {
            return 'Expires today';
        }

        if ($days === 1) {
            return 'Expires tomorrow';
        }

        return sprintf('Expires in %d days', $days);
    }

    /**
     * Label for the batch selector, e.g. "B-102 · 12 left · Expires in 12 days".
     */
    public function displayLabel(): string
    {
        return sprintf(
            '%s · %d left · %s',
            $this->batch_no ?: 'Unlabelled lot',
            (int) $this->quantity,
            $this->expiryLabel(),
        );
    }

    /**
     * Whether FEFO may draw from this lot.
     */
    public function isSellable(): bool
    {
        return (int) $this->quantity > 0 && ! $this->isExpired();
    }
}
