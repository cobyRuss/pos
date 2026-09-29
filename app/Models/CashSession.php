<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One cash drawer's counted worth of trading.
 *
 * A session is the physical drawer, not a shift in the payroll sense: the
 * cashier opens it with a float, sells all day, then counts what is actually in
 * the till and enters it. The difference against what the orders and refunds say
 * should be there is the variance - the only figure in the system that comes
 * from physically counting money rather than from arithmetic on rows.
 */
class CashSession extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'opening_float',
        'opened_at',
        'closed_at',
        'counted_cash',
        'variance_reason',
        'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    /**
     * Cash that went into the drawer during this session.
     *
     * Only cash sales count. GCash never touched the drawer, and counting it
     * would put money in the till that was never physically there.
     */
    public function cashSales(): float
    {
        return round((float) Order::query()
            ->where('user_id', $this->user_id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->where('payment_method', PaymentMethod::Cash->value)
            ->whereBetween('created_at', [$this->opened_at, $this->closed_at ?? now()])
            ->sum('total'), 2);
    }

    /**
     * Cash that left the drawer during this session as refunds.
     */
    public function cashRefunds(): float
    {
        return round((float) Refund::query()
            ->where('user_id', $this->user_id)
            ->where('method', PaymentMethod::Cash->value)
            ->whereBetween('refunded_at', [$this->opened_at, $this->closed_at ?? now()])
            ->sum('amount'), 2);
    }

    /**
     * What should physically be in the drawer when this session ends.
     *
     * Derived on every read rather than frozen at close, so a refund filed after
     * the drawer was counted still lands in the right session's arithmetic.
     */
    public function expectedCash(): float
    {
        return round((float) $this->opening_float + $this->cashSales() - $this->cashRefunds(), 2);
    }

    /**
     * Counted minus expected. Positive is over, negative is short.
     *
     * Zero for a session that was never counted - an open drawer has no count
     * to compare against yet.
     */
    public function variance(): float
    {
        if ($this->counted_cash === null) {
            return 0.0;
        }

        return round((float) $this->counted_cash - $this->expectedCash(), 2);
    }

    public function isShort(): bool
    {
        return $this->variance() < 0;
    }

    public function isOver(): bool
    {
        return $this->variance() > 0;
    }
}
