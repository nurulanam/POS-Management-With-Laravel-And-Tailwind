<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Branch extends Model
{
    protected $fillable = [
        'name', 'code', 'is_active', 'email', 'phone', 'address',
        'currency', 'timezone', 'tax_rate', 'receipt_footer',
    ];

    protected $casts = ['is_active' => 'boolean', 'tax_rate' => 'decimal:2'];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->isAdmin(), fn (Builder $query) =>
            $query->whereHas('users', fn (Builder $users) => $users->whereKey($user->id))
        );
    }
}
