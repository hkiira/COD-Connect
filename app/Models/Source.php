<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Source extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'title',
        'statut',
        'account_id'
    ];

    public function brand_source()
    {
        return $this->hasMany(BrandSource::class);
    }

    public function commissions()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }
    public function brands()
    {
        return $this->belongsToMany(Brand::class, 'brand_source');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [1, 2])
            ->withPivot('statut');
    }
    public function imageables()
    {

        return $this->hasMany(Imageable::class, 'imageable_id', 'id');
    }
}
