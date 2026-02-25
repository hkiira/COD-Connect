<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class MouvementPva extends Model
{
    use SoftDeletes;
    protected $table = 'mouvement_pva';
    use SoftDeletes;

    protected $fillable = [
        'mouvement_id',
        'product_variation_attribute_id',
        'quantity',
        'price',
        'account_user_id',
        'statut'
    ];
    public function mouvement()
    {
        return $this->belongsTo(Mouvement::class);
    }

    public function productVariationAttribute(){
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id', 'id');
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function supplierOrder(){
        return $this->belongsTo(SupplierOrder::class);
    }

    public function supplierReceipt(){
        return $this->belongsTo(SupplierReceipt::class);
    }
    public function orderPvas()
    {
        return $this->belongsToMany(OrderPva::class, 'mouvement_order_pva');
    }

}
