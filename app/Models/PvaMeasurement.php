<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait
use Illuminate\Database\Eloquent\Model;

class PvaMeasurement extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'pva_measurement';
    protected $fillable = ['code', 'barcode',  'product_variation_attribute_id', 'measurement_id','quantity','statut'];

    public function parentPVA()
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }
    
    public function childPVA()
    {
        return $this->hasMany(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variationAttribute()
    {
        return $this->belongsTo(VariationAttribute::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    
    public function orderProducts()
    {
        return $this->hasMany(orderProduct::class);
    }

    public function warehouseProducts()
    {
        return $this->hasMany(WarehouseProduct::class);
        
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_product');
    }

    public function measurements()
    {
        return $this->belongsToMany(Measurement::class, 'pva_measurement');
    }

    public function offer_product()
    {
        return $this->hasMany(ProductOffer::class);
        
    }

    public function activeSuppliers()
    {
        return $this->belongsToMany(Supplier::class,'product_supplier')
            ->wherePivotIn('statut', [1])
            ->withPivot('price');

    }

    public function activeOffers()
    {
        return $this->belongsToMany(Offer::class,'product_offer')
            ->wherePivotIn('statut', [1]);

    }

    public function offers()
    {
        return $this->belongsToMany(Offer::class, 'product_offer');
    }
    
    public function supplier_product()
    {
        return $this->hasMany(ProductSupplier::class);
        
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class,'product_supplier');
    }
}
