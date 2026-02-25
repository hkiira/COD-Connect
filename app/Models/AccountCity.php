<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountCity extends Model
{
    use HasFactory;
    protected $table = 'account_city' ;

    protected $fillable=[
        'city_id',
        'account_id',
        'prefered',
        'statut',
        'created_at',
    	'updated_at'	
    ];
    public function cities(){
        return $this->belongsTo(City::class, 'city_id', 'id');
    }

    public function accounts(){
        return $this->belongsTo(Account::class);
    }

    public function account_carriers()
    {
        return $this->belongsToMany(AccountCarrier::class, 'account_carrier_city');
        
    }
    public function account_carrier_account_city()
    {
        return $this->hasMany(AccountCarrierCity::class);
        
    }

    public function orders(){
        return $this->hasMany(Order::class);
    }

    public function has_addresses(){
        return $this->hasMany(Address::class);
    }
    public function account_user_order(){
        return $this->belongsToMany(AccountUser::class, 'orders');
    }
    public function customer_order(){
        return $this->belongsToMany(Customer::class, 'orders');
    }
    public function payment_type_order(){
        return $this->belongsToMany(PaymentType::class, 'orders');
    }
    public function payment_method_order(){
        return $this->belongsToMany(PaymentMethod::class, 'orders');
    }
    public function brand_source_order(){
        return $this->belongsToMany(BrandSource::class, 'orders');
    }
    public function pickup_order(){
        return $this->belongsToMany(Pickup::class, 'orders');
    }
    public function statuses_order(){
        return $this->belongsToMany(Status::class, 'orders');
    }

    public function payment_commision_order(){
        return $this->belongsToMany(PaymentCommission::class, 'orders');
    }

    public function invoice_order(){
        return $this->belongsToMany(Invoice::class, 'orders');
    }
}
