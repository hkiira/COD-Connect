<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Phone extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'account_id',
        'title'
    ];

    public function phoneTypes()
    {
        return $this->belongsToMany(PhoneTypes::class,'phone_type','phone_id','phone_type_id');
    }
    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'phoneable');
    }

    public function users()
    {
        return $this->morphedByMany(User::class, 'phoneable');
    }

    public function suppliers()
    {
        return $this->morphedByMany(Supplier::class, 'phoneable');
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'phoneable');
    }
    public function orders()
    {
        return $this->morphedByMany(Order::class, 'phoneable');
    }
    public function carriers()
    {
        return $this->morphedByMany(Carrier::class, 'phoneable');
    }
    public function collectors()
    {
        return $this->morphedByMany(Collector::class, 'phoneable');
    }
    
}
