<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Attribute extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'meta',
        'title',
        'statut',
        'account_user_id',
        'type_attribute_id'
    ];
    protected $casts = [
        'meta' => 'array', // Auto-casts JSON to an array
    ];

    public function typeAttribute()
    {
        return $this->belongsTo(TypeAttribute::class, 'types_attribute_id', 'id');
    }

    public function variationAttributes()
    {
        return $this->hasMany(VariationAttribute::class);
    }
    public function products()
    {
        return $this->belongsToMany(Product::class);
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
