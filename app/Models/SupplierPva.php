<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierPva extends Model
{
    protected $table = 'supplier_pva';
    use HasFactory;
    protected $fillable =[
        'product_variation_attribute_id',
        'supplier_id',
        'price',
        'statut',
        'account_id'
    ];

    public function productVariationAttributes()
    {
        return $this->belongsTo(ProductVariationAttribute::class);
    }
    
    public function suppliers()
    {
        return $this->belongsTo(Supplier::class);
    }
}
