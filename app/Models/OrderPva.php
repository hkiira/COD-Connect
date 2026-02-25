<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OrderPva extends Model
{
    use SoftDeletes;
    protected $table = 'order_pva';
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'account_user_id',
        'commissionable_variation_id',
        'offerable_variation_id',
        'order_id',
        'price',
        'realprice',
        'discount',
        'initial_price',
        'quantity',
        'principale',
        'shipping_price',
        'created_at',
        'updated_at',
        'product_variation_attribute_id',
        'order_status_id'
    ];

    public function account_users()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function payments()
    {
        return $this->morphToMany(Payment::class, 'paymentable');
    }
    public function orderStatus()
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function offerableVariation()
    {
        return $this->belongsTo(OfferableVariation::class);
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class);
    }
    public function mouvementPvas()
    {
        return $this->belongsToMany(MouvementPva::class, 'mouvement_order_pva');
    }
}
