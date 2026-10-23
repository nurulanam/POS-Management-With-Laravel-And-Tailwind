<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['price_minor' => 'integer', 'stock' => 'integer', 'reorder_level' => 'integer', 'is_active' => 'boolean'];

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }
}
