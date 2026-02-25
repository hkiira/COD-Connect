<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountUserOrderStatus extends Model
{
    use SoftDeletes;
    protected $table = 'account_user_order_status';
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'account_user_id',
        'order_status_id',
        'order_id',
        'created_at',
        'updated_at',
        'statut'
    ];
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function orderStatus()
    {
        return $this->belongsTo(OrderStatus::class);
    }
    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class);
    }
    public function payments()
    {
        return $this->morphToMany(Payment::class, 'paymentable');
    }
}
