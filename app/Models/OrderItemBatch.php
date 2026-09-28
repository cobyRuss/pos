<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How much of a sold line came out of one delivery lot, and how much of it is
 * still out.
 *
 * A first-expiry-first-out sale can span several lots, so this split - not a
 * single lot id - is what a cancellation or refund reverses. Rows are created
 * in the order the lots were drawn, which is expiry order, so walking them in
 * id order returns the soonest-expiring units first.
 */
class OrderItemBatch extends Model
{
    protected $fillable = [
        'order_item_id',
        'batch_id',
        'quantity',
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

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }
}
