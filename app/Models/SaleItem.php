<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['quantity' => 'integer', 'unit_price_minor' => 'integer', 'line_total_minor' => 'integer'];
}
