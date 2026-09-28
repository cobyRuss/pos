<?php

namespace App\Models;

use App\Enums\DiscountType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_number',
        'user_id',
        'status',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_amount',
        'total',
        'refunded_amount',
        'paid_amount',
        'change_amount',
        'payment_method',
        'customer_note',
        'cancelled_at',
        'cancel_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'discount_type' => DiscountType::class,
            'payment_method' => PaymentMethod::class,
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * The cashier who rang up this sale.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', '!=', OrderStatus::Cancelled);
    }

    public function scopeRevenue(Builder $query): Builder
    {
        return $query->where('status', '!=', OrderStatus::Cancelled);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('order_number', 'like', $like)
                ->orWhere('customer_note', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like));
        });
    }

    public function getCashierNameAttribute(): string
    {
        return $this->user?->name ?? 'Unknown';
    }

    public function getNetTotalAttribute(): float
    {
        return round((float) $this->total - (float) $this->refunded_amount, 2);
    }

    public function getTotalQuantityAttribute(): int
    {
        return (int) $this->items->sum('quantity');
    }

    public function isCancelled(): bool
    {
        return $this->status === OrderStatus::Cancelled;
    }

    public function isFullyRefunded(): bool
    {
        return $this->status === OrderStatus::Refunded;
    }

    /**
     * Items that still have refundable quantity left.
     */
    public function refundableItems(): HasMany
    {
        return $this->items()
            ->whereColumn('refunded_quantity', '<', 'quantity');
    }

    /**
     * Sum of order total minus refunds, used by reports.
     */
    public function netRevenue(): float
    {
        return round((float) $this->total - (float) $this->refunded_amount, 2);
    }

    public static function generateOrderNumber(): string
    {
        do {
            $number = 'ORD-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (static::where('order_number', $number)->exists());

        return $number;
    }
}
