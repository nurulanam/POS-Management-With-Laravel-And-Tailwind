<?php

namespace App\Models;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['version' => 'integer', 'source_snapshot' => 'array', 'destination_snapshot' => 'array', 'dispatched_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    public function items(): HasMany { return $this->hasMany(StockTransferItem::class); }
    public function receipts(): HasMany { return $this->hasMany(TransferReceipt::class); }
    public function scopeForBranch(Builder $query, int $id): Builder
    {
        return $query->where(fn ($q) => $q->where('source_branch_id', $id)->orWhere('destination_branch_id', $id));
    }
}
