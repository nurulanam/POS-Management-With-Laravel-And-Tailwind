<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, HasMany};

class TransferReceipt extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['received_at' => 'immutable_datetime'];
    public function transfer(): BelongsTo { return $this->belongsTo(StockTransfer::class, 'stock_transfer_id'); }
    public function items(): HasMany { return $this->hasMany(TransferReceiptItem::class); }
}
