<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransferReceiptItem extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['received_quantity' => 'integer', 'damaged_quantity' => 'integer', 'missing_quantity' => 'integer'];
    public function transferItem(): BelongsTo { return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id'); }
}
