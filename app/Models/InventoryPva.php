<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryPva extends Model
{
    use SoftDeletes;
    protected $table = 'inventory_pva';
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'account_user_id',
        'product_variation_attribute_id',
        'inventory_id',
        'quantity',
        'statut'
    ];
    
    public function accountUser(){
        return $this->belongsTo(AccountUser::class);
    }
    
    public function inventory(){
        return $this->belongsTo(Inventory::class);
    }
    public function productVariationAttribute(){
        return $this->belongsTo(ProductVariationAttribute::class);
    }

}
