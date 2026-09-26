<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'category_id', 'name', 'sku', 'barcode', 'description',
    'price', 'cost', 'stock', 'low_stock_threshold', 'image', 'is_active',
    'tracks_expiry', 'expiry_warning_days',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $attributes = [
        'stock' => 0,
        'low_stock_threshold' => 5,
        'is_active' => true,
        'tracks_expiry' => false,
        'expiry_warning_days' => 3,
    ];

    /**
     * The batches this product's stock is made of. Only meaningful when
     * tracks_expiry is on; otherwise the product has at most the single
     * undated legacy lot.
     */
    public function lots(): HasMany
    {
        return $this->hasMany(ProductLot::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function lowStock(Builder $query): void
    {
        $query->whereColumn('stock', '<=', 'low_stock_threshold');
    }

    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $query->when($term, function (Builder $query, string $term) {
            $query->where(function (Builder $query) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $query->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like);
            });
        });
    }

    protected function isLowStock(): Attribute
    {
        return Attribute::get(fn () => $this->stock <= $this->low_stock_threshold);
    }

    /**
     * The batch that FEFO would sell from next, and the date it expires.
     * Nulls when the product does not track expiry or has no stock.
     */
    protected function nextLot(): Attribute
    {
        return Attribute::get(function (): ?ProductLot {
            if (! $this->tracks_expiry) {
                return null;
            }

            return $this->relationLoaded('lots')
                ? $this->lots->where('quantity', '>', 0)->sortBy(
                    fn (ProductLot $lot) => [$lot->expires_at === null ? 1 : 0, $lot->expires_at?->timestamp ?? PHP_INT_MAX, $lot->id]
                )->first()
                : $this->lots()->inStock()->fefo()->first();
        });
    }

    protected function nextExpiry(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->next_lot?->expires_at?->toDateString());
    }

    /**
     * Sellable units excluding expired batches. products.stock counts expired
     * stock too, so the till must not offer it.
     */
    protected function sellableStock(): Attribute
    {
        return Attribute::get(function (): int {
            if (! $this->tracks_expiry) {
                return (int) $this->stock;
            }

            if ($this->relationLoaded('lots')) {
                return (int) $this->lots->where('is_sellable', true)->sum('quantity');
            }

            return (int) $this->lots()->inStock()
                ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhereDate('expires_at', '>=', today()->toDateString()))
                ->sum('quantity');
        });
    }

    protected function hasExpiredStock(): Attribute
    {
        return Attribute::get(function (): bool {
            if (! $this->tracks_expiry) {
                return false;
            }

            if ($this->relationLoaded('lots')) {
                return $this->lots->contains(fn (ProductLot $lot) => $lot->is_expired && $lot->quantity > 0);
            }

            return $this->lots()->expired()->inStock()->exists();
        });
    }

    protected function isOutOfStock(): Attribute
    {
        return Attribute::get(fn () => $this->stock <= 0);
    }

    protected function imageUrl(): Attribute
    {
        return Attribute::get(fn () => $this->image ? Storage::disk('public')->url($this->image) : null);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
