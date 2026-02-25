<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Carrier extends Model
{
    use HasFactory;
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'account_id',
        'code',
        'title',
        'email',
        'trackinglink',
        'autocode',
        'comment',
        'statut',
    ];

    public function accounts()
    {
        return $this->belongsToMany(Account::class);
    }
    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }

    public function activePhones()
    {
        return $this->morphToMany(Phone::class, 'phoneable')
            ->wherePivot('statut', 1);
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable');
    }
    public function accountCarriers()
    {
        return $this->hasMany(AccountCarrier::class);
    }

    public function defaultCarriers()
    {
        return $this->hasMany(DefaultCarrier::class);
    }
    public function pickups()
    {
        return $this->hasMany(Pickup::class);
    }
    public function cities()
    {
        return $this->belongsToMany(City::class, 'default_carriers')
            ->withPivot('delivery_time', 'price', 'return', 'statut')
            ->withTimestamps();
    }
}
