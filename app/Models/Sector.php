<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Sector extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = ['title', 'description', 'city_id', 'statut'];

    public function city()
    {
        return $this->belongsTo(City::class);
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
    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'locationable', 'account_locations', 'locationable_id', 'account_id');
    }
}   
