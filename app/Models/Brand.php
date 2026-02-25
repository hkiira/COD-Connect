<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Brand extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'title',
        'website',
        'email',
        'photo',
        'photo_dir',
        'statut',
        'account_id'
    ];

    public function brand_sources()
    {
        return $this->hasMany(BrandSource::class);
    }
    public function sources()
    {
        return $this->belongsToMany(Source::class)->withPivot('id');
    }

    public function compensationable()
    {
        return $this->morphToMany(Compensationable::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
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
