<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Address extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'title','city_id','account_id'
    ] ;
    

    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'addressable');
    }

    public function users()
    {
        return $this->morphedByMany(User::class, 'addressable');
    }

    public function suppliers()
    {
        return $this->morphedByMany(Supplier::class, 'addressable');
    }
    public function carriers()
    {
        return $this->morphedByMany(Carrier::class, 'addressable');
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'addressable');
    }
    public function orders()
    {
        return $this->morphedByMany(Order::class, 'addressable');
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
