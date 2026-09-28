<?php

namespace App\Models;

use App\Enums\RefundNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record of one attempt to alert the owner about a refund.
 *
 * The refund itself is the source of truth; this exists so a silent delivery
 * failure is still visible. An alert that quietly stops working is worse than no
 * alert at all, because the owner stops expecting to hear about anything.
 */
class RefundNotification extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'refund_id',
        'channel',
        'status',
        'attempts',
        'error',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RefundNotificationStatus::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    public function isDelivered(): bool
    {
        return $this->status === RefundNotificationStatus::Sent;
    }

    public function canRetry(): bool
    {
        return $this->status !== RefundNotificationStatus::Sent;
    }
}
