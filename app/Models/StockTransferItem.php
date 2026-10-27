<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransferItem extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['quantity' => 'integer', 'received_quantity' => 'integer', 'damaged_quantity' => 'integer', 'missing_quantity' => 'integer'];

    public function remaining(): int { return $this->quantity - $this->received_quantity - $this->damaged_quantity - $this->missing_quantity; }
}
