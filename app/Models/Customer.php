<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Customer extends Model
{
    use SoftDeletes;
    use HasFactory;
    protected $dates = ['deleted_at'];
    protected $fillable = ['code', 'customer_type_id', 'sector_id', 'ice', 'latitude', 'longtitude', 'statut', 'name', 'comment', 'facebook', 'note', 'account_id'];
    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable')->orderBy('phoneables.created_at', 'desc');
    }
    public function activePhones()
    {
        return $this->morphToMany(Phone::class, 'phoneable')
            ->where('phoneables.statut', 1)->orderBy('phoneables.created_at', 'desc');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable')->orderBy('addressables.created_at', 'desc');
    }
    public function activeAddresses()
    {
        return $this->morphToMany(Address::class, 'addressable')
            ->where('addressables.statut', 1)->orderBy('addressables.created_at', 'desc');
    }
    public function Compensations()
    {
        return $this->morphToMany(Compensation::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }

    // belongsTo
    public function accounts()
    {
        return $this->belongsTo(Customer::class);
    }
    public function sector()
    {
        return $this->belongsTo(Sector::class);
    }
    public function customerType()
    {
        return $this->belongsTo(CustomerType::class);
    }

    //order
    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function account_user_order()
    {
        return $this->belongsToMany(AccountUser::class, 'orders');
    }
    public function account_city_order()
    {
        return $this->belongsToMany(AccountCity::class, 'orders');
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
    public function statuses_order()
    {
        return $this->belongsToMany(Status::class, 'orders');
    }

    public function payment_commision_order()
    {
        return $this->belongsToMany(PaymentCommission::class, 'orders');
    }

    public function invoice_order()
    {
        return $this->belongsToMany(Invoice::class, 'orders');
    }
}
