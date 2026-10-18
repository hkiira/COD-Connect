<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class SupplierReceipt extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 0;
    public const STATUS_VALIDATED = 1;

    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'supplier_id',
        'warehouse_id',
        'account_user_id',
        'mouvement_id',
        'created_at',
        'updated_at',
        'statut'
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id', 'id');
    }

    public function mouvement()
    {
        return $this->belongsTo(Mouvement::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function supplierOrderProduct()
    {
        return $this->hasMany(SupplierOrderPva::class);
    }

    public function supplierReceiptPvas()
    {
        return $this->hasMany(SupplierOrderPva::class, 'supplier_receipt_id', 'id');
    }

    public function supplierOrderPvas()
    {
        return $this->hasMany(SupplierOrderPva::class, 'supplier_receipt_id', 'id');
    }

    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'supplier_order_pva')
            ->withPivot(['id', 'quantity', 'hors_commande_quantity', 'price', 'statut', 'supplier_order_id']);
    }

    public function accountUsers()
    {
        return $this->belongsToMany(AccountUser::class, 'supplier_order_pva');
    }

    public function supplierOrders()
    {
        return $this->belongsToMany(SupplierOrder::class, 'supplier_order_pva');
    }

    public function allocations()
    {
        return $this->hasMany(SupplierOrderReceiptAllocation::class, 'supplier_receipt_id');
    }
}
