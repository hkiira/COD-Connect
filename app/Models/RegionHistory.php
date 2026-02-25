<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegionHistory extends Model
{
    protected $fillable = ['region_id', 'changes', 'user_id'];

    public function region()
    {
        return $this->belongsTo(Region::class);
    }
}
