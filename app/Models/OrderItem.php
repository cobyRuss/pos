<?php

namespace App\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_id', 'product_id', 'product_name', 'sku',
    'unit_price', 'unit_cost', 'quantity', 'discount_amount', 'line_total',
])]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    /**
     * The batches this line drew from, when the product tracks expiry. More
     * than one when a single line consumed several batches under FEFO.
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(OrderItemLot::class);
    }

    /**
     * Margin earned on this line, using the cost captured at sale time.
     */
    protected function profit(): Attribute
    {
        return Attribute::get(function (): float {
            $cost = $this->unit_cost === null ? 0.0 : (float) $this->unit_cost;

            return round((float) $this->line_total - ($cost * (int) $this->quantity), 2);
        });
    }

    protected function refundedQuantity(): Attribute
    {
        return Attribute::get(fn () => (int) $this->refundItems()->sum('quantity'));
    }

    protected function returnableQuantity(): Attribute
    {
        return Attribute::get(fn () => max(0, (int) $this->quantity - $this->refundedQuantity));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity' => 'integer',
        ];
    }
}
