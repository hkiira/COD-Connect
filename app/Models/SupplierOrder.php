<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierOrder extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 0;
    public const STATUS_PENDING = 1;
    public const STATUS_PARTIAL = 2;
    public const STATUS_COMPLETED = 3;
    public const STATUS_CANCELLED = 4;

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
                ->withPivot(['id', 'quantity', 'received_quantity', 'cancelled_quantity', 'price', 'statut', 'supplier_receipt_id']);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class, 'account_user_id', 'id');
    }

    public function supplierReceipts()
    {
        return $this->belongsToMany(SupplierReceipt::class, 'supplier_order_pva');
    }

    public function allocations()
    {
        return $this->hasMany(SupplierOrderReceiptAllocation::class, 'supplier_order_id');
    }
}
