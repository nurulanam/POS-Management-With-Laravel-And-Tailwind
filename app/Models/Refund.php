<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer', 'refunded_at' => 'immutable_datetime'];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('sale', fn ($sales) => $sales->visibleTo($user));
    }
}
