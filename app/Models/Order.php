<?php

namespace App\Models;

use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'order_number', 'user_id', 'subtotal', 'discount_type', 'discount_value',
    'discount_amount', 'tax_rate', 'tax_amount', 'total', 'refunded_total',
    'payment_method', 'reference_no', 'amount_paid', 'change_amount',
    'walkin_customer_name', 'notes', 'status', 'cancelled_at', 'cancel_reason', 'cancelled_by',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', self::STATUS_COMPLETED);
    }

    #[Scope]
    protected function forUser(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }

    #[Scope]
    protected function betweenDates(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('created_at', [$from, $to]);
    }

    protected function isCancelled(): Attribute
    {
        return Attribute::get(fn () => $this->status === self::STATUS_CANCELLED);
    }

    protected function netTotal(): Attribute
    {
        return Attribute::get(fn () => round((float) $this->total - (float) $this->refunded_total, 2));
    }

    protected function fullyRefunded(): Attribute
    {
        return Attribute::get(fn () => (float) $this->refunded_total >= (float) $this->total);
    }

    protected function partiallyRefunded(): Attribute
    {
        return Attribute::get(fn () => (float) $this->refunded_total > 0 && ! $this->fullyRefunded);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }
}
