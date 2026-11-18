<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
    use BelongsToAccount;

    public const STATUSES = ['new', 'contacted', 'interested', 'converted', 'not_interested'];

    protected $fillable = [
        'account_id', 'source', 'external_id', 'lead_date', 'name', 'phone', 'email', 'city', 'address',
        'status', 'notes', 'metadata', 'raw_data', 'imported_file', 'order_id',
    ];

    protected $casts = [
        'lead_date' => 'datetime',
        'metadata' => 'array',
        'raw_data' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
