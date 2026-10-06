<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraCityChange extends Model
{
    public const ADDED = 'added';
    public const RENAMED = 'renamed';
    public const PRICE = 'price';
    public const REMOVED = 'removed';

    protected $fillable = ['afra_city_id', 'type', 'old_value', 'new_value', 'auto_action', 'resolved_at', 'resolved_by'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
