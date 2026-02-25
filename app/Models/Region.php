<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Region extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'id',
        'title',
        'country_id',
        'statut'
    ];
    
/**
 * Get the user that owns the region
 *
 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
 */
    public function history()
    { 
        return $this->hasMany(RegionHistory::class);
    }
    public function accountCompensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id',1);
    }
    public function cities()
    {
        return $this->hasMany(City::class);
    }
    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'locationable', 'account_locations', 'locationable_id', 'account_id');
    }
    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}

