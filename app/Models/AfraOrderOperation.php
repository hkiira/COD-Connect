<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraOrderOperation extends Model
{
    protected $fillable = ['order_id', 'create_state', 'return_state', 'last_error'];
}
