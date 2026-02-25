<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Collector extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'account_carrier_id',
        'photo',
        'photo_dir',
        'statut'
    ];
    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }

    public function accounts()
    {
        return $this->belongsTo(Account::class);
    }

    public function pickups()
    {
        return $this->hasMany(Pickup::class);
    }

    public function account_carrier()
    {
        return $this->belongsToMany(AccountCarrier::class, 'pickups')
        ->withPivot('code')
        ;
    }

    public function account_user()
    {
        return $this->belongsToMany(AccountUser::class, 'pickups')
        ->withPivot('code');

    }
    
}
