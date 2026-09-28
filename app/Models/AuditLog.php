<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'user_name',
        'action',
        'description',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'auditable_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getActorAttribute(): string
    {
        return $this->user_name ?? $this->user?->name ?? 'System';
    }

    public function getActionBadgeClassAttribute(): string
    {
        return match (true) {
            str_contains($this->action, 'delete'), str_contains($this->action, 'cancel') => 'text-bg-danger',
            str_contains($this->action, 'create') => 'text-bg-success',
            str_contains($this->action, 'update') => 'text-bg-warning',
            str_contains($this->action, 'login') => 'text-bg-secondary',
            default => 'text-bg-primary',
        };
    }
}
