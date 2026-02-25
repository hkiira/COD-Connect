<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class City extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'title',
        'statut',
        'region_id',
    ];
    public function history()
    {
        return $this->hasMany(CityHistory::class);
    }
    public function accountCompensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }
    public function accounts()
    {
        return $this->morphToMany(Account::class, 'locationable', 'account_locations', 'locationable_id', 'account_id');
    }

    public function region()
    {
        return $this->belongsTo(Region::class, 'region_id');
    }
    public function sectors()
    {
        return $this->hasMany(Sector::class);
    }
    public function account_city()
    {
        return $this->hasMany(AccountCity::class);
    }
    public function defaultCarriers()
    {
        return $this->hasMany(DefaultCarrier::class);
    }
    public function activeDefaultCarriers()
    {
        return $this->hasMany(DefaultCarrier::class)->where('statut', 1);
    }
    public function carriers()
    {
        return $this->belongsToMany(Carrier::class, 'default_carriers');
    }

    public function activeCarriers()
    {
        return $this->belongsToMany(Carrier::class, 'default_carriers')->where('carriers.statut', 1);
    }
    public function accountCarriers()
    {
        return $this->belongsToMany(AccountCarrier::class, 'account_carrier_city');
    }
}
