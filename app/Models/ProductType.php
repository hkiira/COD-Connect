<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code','title', 'statut'
    ];

    protected $dates = ['deleted_at'];
    
    public function products()
    {
        return $this->hasMany(Product::class);
    }
    
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [1,2])
            ->withPivot('statut');
    }
}
