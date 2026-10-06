<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Last copy of a city in Afra's list (id = Afra's city id). */
class AfraCity extends Model
{
    public $incrementing = false;

    protected $fillable = ['id', 'name', 'delivery_price', 'removed_at'];

    protected $casts = ['delivery_price' => 'float', 'removed_at' => 'datetime'];
}
