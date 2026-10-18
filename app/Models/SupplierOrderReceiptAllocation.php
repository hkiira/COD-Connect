<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierOrderReceiptAllocation extends Model
{
    protected $table = 'supplier_order_receipt_allocations';

    protected $fillable = [
        'supplier_order_id',
        'supplier_order_pva_id',
        'supplier_receipt_id',
        'supplier_receipt_pva_id',
        'product_variation_attribute_id',
        'allocated_quantity',
    ];

    public function supplierOrder()
    {
        return $this->belongsTo(SupplierOrder::class, 'supplier_order_id');
    }

    public function supplierOrderPva()
    {
        return $this->belongsTo(SupplierOrderPva::class, 'supplier_order_pva_id');
    }

    public function supplierReceipt()
    {
        return $this->belongsTo(SupplierReceipt::class, 'supplier_receipt_id');
    }

    public function supplierReceiptPva()
    {
        return $this->belongsTo(SupplierOrderPva::class, 'supplier_receipt_pva_id');
    }

    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }
}
