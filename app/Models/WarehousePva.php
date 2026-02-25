<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WarehousePva extends Model
{
    protected $table = 'warehouse_pva';
    protected $fillable = ['product_variation_attribute_id', 'warehouse_id', 'quantity', 'statut'];

    // Define relationships or additional methods as needed

    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
