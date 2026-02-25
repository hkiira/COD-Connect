<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class DefaultCarrier extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'default_carriers';
    protected $fillable = [
        'id',
        'carrier_id',
        'city_id',
        'city_id_carrier',
        'name',
        'price',
        'return',
        'delivery_time',
        'statut'
    ];
    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
