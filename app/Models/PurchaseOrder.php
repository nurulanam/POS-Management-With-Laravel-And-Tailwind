<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'version' => 'integer', 'total_minor' => 'integer', 'supplier_snapshot' => 'array', 'branch_snapshot' => 'array',
        'ordered_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime', 'expected_on' => 'immutable_date',
    ];

    public function items(): HasMany { return $this->hasMany(PurchaseOrderItem::class); }

    public function receipts(): HasMany { return $this->hasMany(PurchaseReceipt::class); }
}
