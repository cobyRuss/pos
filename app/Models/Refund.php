<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\RefundNotificationStatus;
use App\Enums\RefundReason;
use App\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'refund_number',
        'order_id',
        'user_id',
        'status',
        'amount',
        'tax_amount',
        'method',
        'reason_code',
        'reason',
        'note',
        'idempotency_key',
        'review_required',
        'reviewed_at',
        'reviewed_by',
        'refunded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundStatus::class,
            'method' => PaymentMethod::class,
            'reason_code' => RefundReason::class,
            'amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'review_required' => 'boolean',
            'reviewed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * The original sale this refund is tied to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The cashier who processed the refund. On a store where the administrator is
     * never on the till, this is the name that matters.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(RefundNotification::class);
    }

    /**
     * The administrator who signed the refund off, if it needed sign-off.
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->where('review_required', true)->whereNull('reviewed_at');
    }

    public function scopeForDay(Builder $query, string $day): Builder
    {
        return $query->whereDate('refunded_at', $day);
    }

    public function getProcessedByAttribute(): string
    {
        return $this->user?->name ?? 'System';
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /**
     * The money leaving the drawer, excluding the tax the store owes back.
     */
    public function netAmount(): float
    {
        return round((float) $this->amount - (float) $this->tax_amount, 2);
    }

    public static function generateRefundNumber(): string
    {
        do {
            $number = 'REF-'.now()->format('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
        } while (static::where('refund_number', $number)->exists());

        return $number;
    }

    /**
     * Create the notification row for a channel, or hand back the one that
     * already exists so a retry updates a single record instead of adding
     * another. The unique (refund_id, channel) index is the real guarantee; this
     * keeps the common path quiet.
     */
    public function notificationFor(string $channel): RefundNotification
    {
        return $this->notifications()->firstOrCreate(
            ['channel' => $channel],
            ['status' => RefundNotificationStatus::Pending],
        );
    }
}
