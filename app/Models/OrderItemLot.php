<?php

namespace App\Models;

use Database\Factories\OrderItemLotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The slice of one batch that a single order line drew from.
 *
 * A line can consume several batches under FEFO, and a single unit can be
 * returned from one batch only, so the mapping is its own table rather than a
 * lot_id column on order_items. This is what lets a refund put the goods back
 * where they came from.
 */
#[Fillable(['order_item_id', 'lot_id', 'quantity'])]
class OrderItemLot extends Model
{
    /** @use HasFactory<OrderItemLotFactory> */
    use HasFactory;

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
        ];
    }
}
