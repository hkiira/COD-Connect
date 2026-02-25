<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class SupplierOrderPva extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'supplier_order_pva';
    protected $fillable = [
        'supplier_order_id',
        'supplier_receipt_id',
        'product_variation_attribute_id',
        'receipt_id',
        'quantity',
        'price',
        'account_user_id',
        'sop_type_id',
        'statut'
    ];

    public function supplierOrder(){
        return $this->belongsTo(SupplierOrder::class);
    }

    public function supplierReceipt(){
        return $this->belongsTo(SupplierReceipt::class);
    }

    public function productVariationAttribute(){
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id', 'id');
    }
}
