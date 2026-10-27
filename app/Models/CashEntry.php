<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['delta_minor' => 'integer', 'balance_minor' => 'integer', 'created_at' => 'immutable_datetime'];
}
