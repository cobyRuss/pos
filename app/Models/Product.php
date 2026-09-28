<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'image_path',
        'description',
        'cost_price',
        'selling_price',
        // The cached sum of this product's batches. Form requests must never
        // expose it: every real change goes through InventoryService, which
        // recomputes it from the lots. See recalculateStock().
        'stock',
        'low_stock_threshold',
        'unit',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /**
     * The deliveries this product is made up of. `stock` is the sum of these.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(ProductBatch::class);
    }

    public function sellableBatches(): HasMany
    {
        return $this->batches()->sellable();
    }

    /**
     * Recompute the cached stock figure from the lots and persist it.
     *
     * products.stock is a denormalised sum of product_batches.quantity. It is
     * written here and nowhere else, so it cannot drift - InventoryService is
     * the only caller, and every stock change goes through it.
     */
    public function recalculateStock(): int
    {
        $total = (int) $this->batches()->sum('quantity');

        if ($total !== (int) $this->stock) {
            $this->forceFill(['stock' => $total])->save();
        }

        return $total;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->whereColumn('stock', '>', 0);
    }

    /**
     * Products with at least one expired lot still on hand.
     */
    public function scopeExpiredStock(Builder $query): Builder
    {
        return $query->whereHas('batches', fn (Builder $b) => $b->expired());
    }

    /**
     * Products with a lot expiring inside the given window.
     */
    public function scopeExpiringStock(Builder $query, int $days = ProductBatch::EXPIRY_WARNING_DAYS): Builder
    {
        return $query->whereHas('batches', fn (Builder $b) => $b->expiringWithin($days));
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->where('is_active', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('name', 'like', $like)
                ->orWhere('description', 'like', $like);
        });
    }

    public function isLowStock(): bool
    {
        return $this->stock <= $this->low_stock_threshold;
    }

    public function isOutOfStock(): bool
    {
        return $this->stock <= 0;
    }

    /**
     * Units that FEFO is actually allowed to sell: everything still held,
     * minus anything already past its date.
     */
    public function sellableStock(): int
    {
        if (! $this->relationLoaded('batches')) {
            return (int) $this->batches()->sellable()->sum('quantity');
        }

        return (int) $this->getRelation('batches')
            ->filter(fn (ProductBatch $batch) => $batch->isSellable())
            ->sum('quantity');
    }

    public function expiredQuantity(): int
    {
        return (int) $this->batches()->expired()->sum('quantity');
    }

    /**
     * Earliest date among the lots still holding units, expired ones included,
     * so the caller can show the soonest deadline. Null when nothing expires.
     */
    public function nextExpiryDate(): ?Carbon
    {
        $earliest = $this->batches()
            ->inStock()
            ->whereNotNull('expiry_date')
            ->orderBy('expiry_date')
            ->first();

        return $earliest?->expiry_date;
    }

    /**
     * Worst status across the lots: expired wins over expiring wins over ok.
     * Products with no dated lots report "none".
     */
    public function expiryStatus(): string
    {
        if (! $this->relationLoaded('batches')) {
            return $this->batches()->inStock()->exists()
                ? $this->expiryStatusFromDatabase()
                : 'none';
        }

        $statuses = ['expired', 'expiring', 'ok'];

        foreach ($statuses as $status) {
            if ($this->getRelation('batches')
                ->contains(fn (ProductBatch $batch) => $batch->status() === $status)) {
                return $status;
            }
        }

        return 'none';
    }

    private function expiryStatusFromDatabase(): string
    {
        if ($this->batches()->expired()->exists()) {
            return 'expired';
        }

        return $this->batches()->expiringWithin()->exists() ? 'expiring' : 'none';
    }

    /**
     * A product holding nothing but expired stock cannot be sold, which is a
     * different problem from being out of stock.
     */
    public function isFullyExpired(): bool
    {
        return $this->stock > 0 && $this->sellableStock() === 0;
    }

    public function hasImage(): bool
    {
        return filled($this->image_path);
    }

    /**
     * Public URL of the product photo, or null when none has been uploaded.
     *
     * Built from the current request root rather than the disk's configured
     * APP_URL, so photos resolve whether the app is served by Apache or by
     * `php artisan serve` on a different host and port.
     */
    public function imageUrl(): ?string
    {
        if (! $this->hasImage()) {
            return null;
        }

        return asset('storage/'.$this->image_path);
    }

    /**
     * Delete the stored photo, if any. Safe to call twice.
     */
    public function deleteImage(): void
    {
        if (! $this->hasImage()) {
            return;
        }

        Storage::disk('public')->delete($this->image_path);

        $this->forceFill(['image_path' => null])->save();
    }

    public function getMarginAttribute(): float
    {
        return (float) $this->selling_price - (float) $this->cost_price;
    }

    public function getStockValueAttribute(): float
    {
        return (float) $this->cost_price * (int) $this->stock;
    }
}
