<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'restocked' => 'boolean', 'subtotal_minor' => 'integer', 'tax_minor' => 'integer', 'total_minor' => 'integer'];

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
