<?php

namespace App\Models;

use Database\Factories\RefundItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'refund_id', 'order_item_id', 'product_id', 'product_name',
    'quantity', 'unit_price', 'amount', 'condition', 'restocked',
])]
class RefundItem extends Model
{
    /** @use HasFactory<RefundItemFactory> */
    use HasFactory;

    public const CONDITION_RESTOCKABLE = 'restockable';

    public const CONDITION_DAMAGED = 'damaged';

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function isRestockable(): Attribute
    {
        return Attribute::get(fn () => $this->condition === self::CONDITION_RESTOCKABLE);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
            'restocked' => 'boolean',
        ];
    }
}
