<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'received_quantity' => 'integer', 'unit_cost_minor' => 'integer', 'total_minor' => 'integer'];
}
