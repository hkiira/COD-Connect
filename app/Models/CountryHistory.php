<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CountryHistory extends Model
{
    protected $fillable = ['country_id', 'changes', 'user_id'];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
