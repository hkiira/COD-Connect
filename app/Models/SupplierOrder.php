<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierOrder extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at','shipping_date'];
    protected $fillable = [
        'code',
        'shipping_date',
        'warehouse_id',
        'supplier_id',
        'account_user_id',
        'statut'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
        
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
        
    }

    public function supplierOrderPvas()
    {
        return $this->hasMany(SupplierOrderPva::class, 'supplier_order_id', 'id');
        
    }

    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'supplier_order_pva')
                ->withPivot(['quantity','price']);
        
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class, 'account_user_id', 'id');
        
    }

    public function supplierReceipts()
    {
        return $this->belongsToMany(SupplierReceipt::class, 'supplier_order_pva');
        
    }
}
