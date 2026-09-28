<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class InventoryMovement extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'batch_id',
        'expiry_date',
        'user_id',
        'type',
        'quantity',
        'before_stock',
        'after_stock',
        'reason',
        'reference_type',
        'reference_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InventoryMovementType::class,
            'quantity' => 'integer',
            'before_stock' => 'integer',
            'after_stock' => 'integer',
            'reference_id' => 'integer',
        ];
    }

    /**
     * A DATE column, normalised on the way in so the snapshot always compares
     * cleanly against a plain date.
     */
    protected function expiryDate(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn (mixed $value) => filled($value)
                ? Carbon::parse($value)->toDateString()
                : null,
        );
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isIncrease(): bool
    {
        return $this->quantity > 0;
    }
}
