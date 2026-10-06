<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraOrderOperation extends Model
{
    protected $fillable = [
        'order_id', 'create_state', 'create_attempted_at', 'return_state', 'delete_state', 'exchange_state',
        'remote_status', 'remote_status_at', 'missing_since', 'last_error',
    ];

    protected $casts = [
        'create_attempted_at' => 'datetime',
        'remote_status_at'    => 'datetime',
        'missing_since'       => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
