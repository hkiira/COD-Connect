<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Country extends Model
{

    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'title',
        'statut'
    ];
    
/**
 * Get the user that owns the region
 *
 * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
 */
public function history()
    {
        return $this->hasMany(CountryHistory::class);
    }
    public function commissions()
    {
        return $this->morphToMany(Commission::class, 'commissionable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id',1);
    }
    public function regions()
    {
        return $this->hasMany(Region::class);
    }
    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'locationable', 'account_locations', 'locationable_id', 'account_id');
    }
    
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [1,2])
            ->withPivot('statut');
    }
    public function imageables()
    {

        return $this->hasMany(Imageable::class, 'imageable_id', 'id');
    }

}

