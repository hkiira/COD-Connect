<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Pickup extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'statut',
        'comment',
        'account_user_id',
        'carrier_id',
        'mouvement_id',
        'warehouse_id',
        'collector_id',
        'title',
        'created_at',
        'updated_at',
    ];
    public function accountUser()
    {
        return  $this->belongsTo(AccountUser::class);
    }

    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
    public function mouvement()
    {
        return $this->belongsTo(Mouvement::class);
    }

    public function collector()
    {
        return $this->belongsTo(Collector::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
