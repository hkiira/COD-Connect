<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Shipment extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'shipment_id',
        'title',
        'comment',
        'warehouse_id',
        'shipment_type_id',
        'account_user_id',
        'carrier_id',
        'mouvement_id',
        'is_return',
        'statut',
        'created_at',
        'updated_at',
    ];
    public function parentShipment()
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
    // Relation avec les commandes enfants
    public function childShipments()
    {
        return $this->hasMany(Shipment::class, 'shipment_id');
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'transaction_id');
    }
    public function orders()
    {
        return $this->hasMany(Order::class, 'shipment_id');
    }
    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    public function warehouse()
    {
        return  $this->belongsTo(Warehouse::class);
    }
    public function mouvement()
    {
        return $this->belongsTo(Mouvement::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function shipmentType()
    {
        return $this->belongsTo(ShipmentType::class);
    }
}
