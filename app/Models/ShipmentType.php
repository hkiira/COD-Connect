<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class ShipmentType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'shipment_types';
    protected $fillable = [
        'title',
        'code',
        'is_carrier',
        'statut'
    ];

    public function shipments()
    {
        return $this->hasMany(shipment::class);
    }
}
