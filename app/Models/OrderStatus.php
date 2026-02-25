<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class OrderStatus extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'title',
        'todelete',
        'statut'
    ];
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    public function orderPvas()
    {
        return $this->hasMany(OrderPva::class);
    }
    public function commissions()
    {
        return $this->morphToMany(Commission::class, 'commissionable');
    }
    public function comments()
    {
        return $this->belongsToMany(Comment::class, 'order_status_comment');
    }
    public function account_user()
    {
        return $this->belongsToMany(AccountUser::class, 'account_user_order_status');
    }
    public function account_city_order()
    {
        return $this->belongsToMany(City::class, 'orders');
    }
    public function payment_type_order()
    {
        return $this->belongsToMany(PaymentType::class, 'orders');
    }
    public function payment_method_order()
    {
        return $this->belongsToMany(PaymentMethod::class, 'orders');
    }
    public function brand_source_order()
    {
        return $this->belongsToMany(BrandSource::class, 'orders');
    }
    public function pickup_order()
    {
        return $this->belongsToMany(Pickup::class, 'orders');
    }
    public function customer()
    {
        return $this->belongsToMany(Customer::class, 'orders');
    }

    public function payment_commision_order()
    {
        return $this->belongsToMany(PaymentCommission::class, 'orders');
    }

    public function invoice_order()
    {
        return $this->belongsToMany(Invoice::class, 'orders');
    }

    public function order_comments()
    {
        return $this->hasMany(OrderComment::class);
    }

    public function account_user_order_comment()
    {
        return $this->belongsToMany(AccountUser::class, 'order_comments');
    }
    public function subcomment_order_comment()
    {
        return $this->belongsToMany(Subcomment::class, 'order_comments');
    }
    public function order_order_comment()
    {
        return $this->belongsToMany(Order::class, 'order_comments');
    }
}
