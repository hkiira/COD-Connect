<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class ProductVariationAttribute extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'product_variation_attribute';
    protected $casts = [
        'meta' => 'array', // Auto-casts JSON to an array
    ];
    protected $fillable = ['code', 'barcode', 'meta', 'product_id', 'variation_attribute_id', 'product_variation_attribute_id', 'quantity', 'account_id', 'statut'];
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }
    public function accountCompensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
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

    public function orderPvas()
    {
        return $this->hasMany(OrderPva::class);
    }
    public function pvaPacks()
    {
        return $this->hasMany(PvaPack::class);
    }

    public function warehousePvas()
    {
        return $this->hasMany(WarehousePva::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_pva');
    }

    public function activeWarehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'warehouse_pva')
            ->wherePivotIn('statut', [1])
            ->withPivot('quantity');
    }

    public function measurements()
    {
        return $this->belongsToMany(Measurement::class, 'pva_measurement');
    }

    public function supplierOrderPvas()
    {
        return $this->hasMany(SupplierOrderPva::class);
    }


    public function activeSuppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_pva')
            ->wherePivotIn('statut', [1])
            ->withPivot('price');
    }

    public function activeOffers()
    {
        return $this->morphedByMany(Offer::class, 'model', 'offerables', 'model_id', 'offer_id')
            ->wherePivotIn('statut', [1]);
    }
    public function supplierPvas()
    {
        return $this->hasMany(supplierPva::class);
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'supplier_pva');
    }
    public function supplierOrders()
    {
        return $this->belongsToMany(SupplierOrder::class, 'supplier_order_pva');
    }
    public function supplierReceipts()
    {
        return $this->belongsToMany(SupplierReceipt::class, 'supplier_order_pva');
    }
    public function orders()
    {
        return $this->belongsToMany(Order::class, 'order_pva');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot('statut');
    }
    public function principalImage()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [2])
            ->withPivot('statut');
    }
    public function has_images()
    {
        return $this->hasMany(Image::class);
    }
    public function imageables()
    {
        return $this->hasMany(Imageable::class, 'imageable_id', 'id');
    }
}
