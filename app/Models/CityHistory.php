<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CityHistory extends Model
{
    protected $fillable = ['city_id', 'changes', 'user_id'];

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
