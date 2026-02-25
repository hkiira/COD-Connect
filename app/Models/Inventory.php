<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Inventory extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'account_user_id',
        'inventory_type_id',
        'mouvement_id',
        'warehouse_id',
        'created_at',
        'updated_at',
        'statut',
    ];
    public function inventoryType(){
        return $this->belongsTo(InventoryType::class);
    }
    public function accountUser(){
        return $this->belongsTo(AccountUser::class);
    }

    public function mouvement(){
        return $this->belongsTo(Mouvement::class);
    }
    public function warehouse(){
        return $this->belongsTo(Warehouse::class);
    }

    public function inventoryPvas(){
        return $this->hasMany(InventoryPva::class);
    }

    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'inventory_pva')
        ->withPivot('quantity');
    }
}
