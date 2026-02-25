<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Supplier extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'title',
        'account_id',
        'statut',
    ];

    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }
    public function has_phones()
    {
        return $this->hasMany(Phone::class);
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }

    public function has_images()
    {
        return $this->hasMany(Image::class);
    }

    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function supplierPvas()
    {
        return $this->hasMany(SupplierPva::class);
        
    }
    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'supplier_pva')
        ->withPivot('statut')
        ->withPivot('price');
        
    }
    public function activePvas()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'supplier_pva')
            ->wherePivotIn('statut', [1])
            ->withPivot('price');
        
    }

    
    
    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class,'warehouse_supplier')
            ->wherePivot('statut', 1);
    }
    public function supplierBillings()
    {
        return $this->hasMany(SupplierBilling::class);
        
    }
    public function account_users_supplier_billing()
    {
        return $this->belongsToMany(AccountUser::class, 'supplier_billings')
        ->withPivot('code', 'montant', 'statut');
        
    }

    
    public function supplierOrders()
    {
        return $this->hasMany(SupplierOrder::class);
        
    }
    public function account_users_supplier_order()
    {
        return $this->belongsToMany(AccountUser::class, 'supplier_orders')
        ->withPivot('code', 'shipping_date', 'statut');
        
    }

    public function supplierReceipts()
    {
        return $this->hasMany(SupplierReceipt::class);
        
    }
    
    public function account_users_supplier_receipt()
    {
        return $this->belongsToMany(AccountUser::class, 'supplier_receipts')
        ->withPivot('code', 'statut');
        
    }
}
