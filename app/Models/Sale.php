<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'branch_snapshot' => 'array', 'customer_snapshot' => 'array', 'paid_at' => 'immutable_datetime',
        'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer',
        'tendered_minor' => 'integer', 'change_minor' => 'integer', 'tax_basis_points' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when($user->role === UserRole::Cashier, fn ($query) => $query->where('user_id', $user->id));
    }
}
