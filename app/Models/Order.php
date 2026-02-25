<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code',
        'meta',
        'shipping_code',
        'customer_id',
        'payment_type_id',
        'warehouse_id',
        'payment_method_id',
        'brand_source_id',
        'pickup_id',
        'order_status_id',
        'invoice_id',
        'shipment_id',
        'offerable_variation_id',
        'is_change',
        'adresse',
        'carrier_price',
        'real_carrier_price',
        'created_at',
        'updated_at',
        'note',
        'city_id',
        'account_id',
        'order_id',
        'score', // Added score field
        'discount',
        'sync'
    ];
    // Relation avec la commande parente (si applicable)
    public function parentOrder()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
    // Relation avec les commandes enfants
    public function childOrders()
    {
        return $this->belongsTo(Account::class);
    }
    public function city()
    {
        return $this->belongsTo(City::class);
    }
    public function paymentType()
    {
        return $this->belongsTo(PaymentType::class);
    }
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
    public function offerableVariation()
    {
        return $this->belongsTo(OfferableVariation::class);
    }
    public function brandSource()
    {
        return $this->belongsTo(BrandSource::class);
    }
    public function pickup()
    {
        return $this->belongsTo(Pickup::class);
    }
    public function orderStatus()
    {
        return $this->belongsTo(OrderStatus::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
    public function shipment()
    {
        return $this->belongsTo(Shipment::class);
    }

    public function orderPvas()
    {
        return $this->hasMany(OrderPva::class);
    }

    public function activeOrderPvas()
    {
        return $this->hasMany(OrderPva::class)->whereNotIn('order_status_id', [2, 3]);
    }
    public function inactiveOrderPvas()
    {
        return $this->hasMany(OrderPva::class)->whereIn('order_status_id', [2, 3]);
    }
    public function accountUserOrderStatus()
    {
        return $this->hasMany(AccountUserOrderStatus::class);
    }
    public function orderStatuses()
    {
        return $this->belongsToMany(OrderStatus::class, 'account_user_order_status');
    }
    public function accountUsers()
    {
        return $this->belongsToMany(AccountUser::class, 'account_user_order_status');
    }
    public function userCreated()
    {
        return $this->belongsToMany(AccountUser::class, 'account_user_order_status')
            ->wherePivotIn('order_status_id', [1, 4])
            ->withPivot('id', 'order_status_id');
    }
    public function comments()
    {
        return $this->belongsToMany(Comment::class, 'order_comment')
            ->orderByPivot('created_at', 'desc')
            ->withPivot('order_status_id', 'account_user_id', 'title');
    }




    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function payments()
    {
        return $this->morphToMany(Payment::class, 'paymentable');
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }
    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable');
    }

    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'order_pva')
            ->withPivot('order_status_id', 'price', 'quantity', 'realprice', 'quantity');
    }


    public function activePvas()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'order_pva')
            ->withPivot('id', 'order_status_id', 'price', 'quantity', 'realprice', 'initial_price', 'discount')
            ->wherePivotNotIn('order_status_id', [2, 3]);
    }

    public function inactivePvas()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'order_pva')
            ->withPivot('id', 'order_status_id', 'price', 'quantity', 'realprice', 'initial_price', 'discount')
            ->wherePivotIn('order_status_id', [2, 3]);
    }

    public function orderComments()
    {
        return $this->hasMany(OrderComment::class);
    }
    public function lastOrderComments()
    {
        return $this->hasMany(OrderComment::class)
            ->orderBy('created_at', 'Desc');
    }
    public function StatusOrderComments()
    {
        return $this->belongsToMany(OrderStatus::class, 'order_comments');
    }

    public function calculateActivePvasTotalValue()
    {
        return $this->activePvas->sum(function ($pva) {
            return $pva->pivot->price * $pva->pivot->quantity;
        });
    }
    public function calculateActivePvasQte()
    {
        return $this->activePvas->sum(function ($pva) {
            return $pva->pivot->quantity;
        });
    }

    public function orderPvaTtitle()
    {
        return $this->activePvas->map(function ($pva) {
            $product = ["product" => $pva->product->title];
            $product['attributes'] = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                return $child->attribute->title;
            })->toArray();
            return $product;
        });
    }
}
