<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraStatusMapping extends Model
{
    protected $fillable = ['account_id', 'afra_status_id', 'order_status_id'];
}
