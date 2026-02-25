<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehouseUser extends Model
{
    use HasFactory;
    protected $table = 'warehouse_user';
    protected $fillable = [

        'account_user_id',
        'warehouse_id',
        'statut'
    ];
    public function warehouses(){
        return $this->belongsTo(Warehouse::class);
    }

    public function accounts(){
        return $this->belongsTo(AccountUser::class);
    }

}
