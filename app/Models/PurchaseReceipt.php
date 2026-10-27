<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class PurchaseReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['total_minor' => 'integer', 'received_at' => 'immutable_datetime'];

    public function order(): BelongsTo { return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id'); }

    public function items(): HasMany { return $this->hasMany(PurchaseReceiptItem::class); }
}
