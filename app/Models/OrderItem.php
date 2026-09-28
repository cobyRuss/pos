<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'product_id',
        'product_name',
        'unit_price',
        'unit_cost',
        'quantity',
        'refunded_quantity',
        'discount_amount',
        'line_total',
        'tax_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'quantity' => 'integer',
            'refunded_quantity' => 'integer',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tax_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The lots this line was drawn from, and how much of each is still out.
     * A cancellation or refund reverses this split rather than guessing.
     */
    public function batchUsages(): HasMany
    {
        return $this->hasMany(OrderItemBatch::class);
    }

    public function refundItems(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function getRefundableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->refunded_quantity);
    }

    /**
     * What one unit of this line actually cost the customer, tax included.
     *
     * This - not `line_total` - is what a refund pays out. It is derived from the
     * tax frozen on the line at sale time, so it survives a later change to the
     * store's tax rate. The refund service owns the exact figures; this is the
     * figure the till shows while the cashier is still picking quantities.
     */
    public function taxInclusiveUnitTotal(): float
    {
        $sold = max(1, (int) $this->quantity);

        return round(((float) $this->line_total + (float) $this->tax_amount) / $sold, 4);
    }

    /**
     * Everything still owed back on this line, tax included.
     */
    public function refundableTaxInclusiveTotal(): float
    {
        $sold = max(1, (int) $this->quantity);
        $gross = round((float) $this->line_total + (float) $this->tax_amount, 2);
        $returned = round((float) $this->refundItems()->sum('amount'), 2);

        return round(max(0.0, $gross - $returned), 2);
    }

    /**
     * What the shop paid for the units on this line.
     */
    public function lineCost(): float
    {
        return round((float) $this->unit_cost * $this->quantity, 2);
    }

    /**
     * Margin on this line after discounts, excluding any refunded units.
     */
    public function lineProfit(): float
    {
        $sold = $this->refundable_quantity;

        return round((float) $this->line_total - ((float) $this->unit_cost * $sold), 2);
    }

    public function getUnitMarginAttribute(): float
    {
        return round((float) $this->unit_price - (float) $this->unit_cost, 2);
    }

    public function getUnitMarginPercentAttribute(): float
    {
        $price = (float) $this->unit_price;

        return $price > 0 ? round(((float) $this->unit_margin / $price) * 100, 1) : 0.0;
    }
}
