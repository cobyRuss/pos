<?php

namespace App\Models;

use Database\Factories\ProductLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A batch of a product: a quantity that arrived together, with its own expiry
 * and cost.
 *
 * Stock that tracks expiry is allocated batch by batch under FEFO, so this is
 * the real unit of inventory for those products. products.stock remains a
 * cached total of these quantities.
 */
#[Fillable(['product_id', 'code', 'expires_at', 'quantity', 'cost', 'received_at', 'notes'])]
class ProductLot extends Model
{
    /** @use HasFactory<ProductLotFactory> */
    use HasFactory;

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    /**
     * The slices of this batch that have been sold, via order lines.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderItemLot::class);
    }

    /**
     * FEFO order: soonest expiry first, and a lot with no recorded expiry
     * last, because "unknown" must never be treated as "most urgent".
     */
    #[Scope]
    protected function fefo(Builder $query): void
    {
        $query
            ->orderByRaw('(expires_at IS NULL) ASC')
            ->orderBy('expires_at')
            ->orderBy('id');
    }

    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->where('quantity', '>', 0);
    }

    #[Scope]
    protected function expired(Builder $query, ?Carbon $asOf = null): void
    {
        $asOf ??= Carbon::now();

        $query->whereNotNull('expires_at')->whereDate('expires_at', '<', $asOf->toDateString());
    }

    #[Scope]
    protected function expiringWithin(Builder $query, int $days): void
    {
        $query
            ->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', Carbon::now()->toDateString())
            ->whereDate('expires_at', '<=', Carbon::now()->addDays($days)->toDateString());
    }

    #[Scope]
    protected function forProduct(Builder $query, int $productId): void
    {
        $query->where('product_id', $productId);
    }

    /**
     * Expiry relative to today, in whole days. Negative means it has passed.
     */
    protected function daysUntilExpiry(): Attribute
    {
        return Attribute::get(function (): ?int {
            if ($this->expires_at === null) {
                return null;
            }

            return (int) Carbon::today()
                ->diffInDays($this->expires_at->startOfDay(), false);
        });
    }

    protected function isExpired(): Attribute
    {
        return Attribute::get(fn (): bool => $this->expires_at !== null
            && $this->expires_at->lt(Carbon::today()));
    }

    /**
     * True when the lot is inside the window a shop would call "use soon".
     */
    protected function isExpiringSoon(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->expires_at === null) {
                return false;
            }

            $days = $this->days_until_expiry;

            return $days !== null && $days >= 0 && $days <= (int) ($this->product?->expiry_warning_days ?? 3);
        });
    }

    protected function isSellable(): Attribute
    {
        return Attribute::get(fn (): bool => $this->quantity > 0 && ! $this->is_expired);
    }

    protected function label(): Attribute
    {
        return Attribute::get(function (): string {
            $expiry = $this->expires_at?->format('d M Y') ?? 'no expiry';

            return "{$this->code} · {$expiry} · {$this->quantity} left";
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'received_at' => 'date',
            'quantity' => 'integer',
            'cost' => 'decimal:2',
        ];
    }
}
