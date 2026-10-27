<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'opening_minor' => 'integer', 'expected_minor' => 'integer', 'counted_minor' => 'integer',
        'variance_minor' => 'integer', 'version' => 'integer', 'opened_at' => 'immutable_datetime',
        'closed_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(CashEntry::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when($user->role === UserRole::Cashier, fn ($q) => $q->where('user_id', $user->id));
    }
}
