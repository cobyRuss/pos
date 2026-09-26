<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'product_id', 'lot_id', 'quantity_change', 'type', 'notes', 'reference_type', 'reference_id'])]
class InventoryLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'inventory_logs';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The batch this movement applied to, when the product tracks expiry.
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class);
    }

    #[Scope]
    protected function ofType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    #[Scope]
    protected function forReference(Builder $query, string $type, int $id): void
    {
        $query->where('reference_type', $type)
            ->where('reference_id', $id);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_change' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
