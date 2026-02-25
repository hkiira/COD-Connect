<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountCarrierCity extends Model
{
    use HasFactory;
    protected $table = 'account_carrier_city' ;

    protected $fillable = [
        'account_carrier_id',
        'city_id',
        'name',
        'price',
        'return',
        'delivery_time',
        'created_at',
        'updated_at',
        'statut'
    ];
    public function accountCarrier(){
        return $this->belongsTo(AccountCarrier::class);
    }

    public function city(){
        return $this->belongsTo(City::class);
    }
}
