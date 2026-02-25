<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class PvaPack extends Model
{
    use SoftDeletes;
    protected $table = 'pva_packs';
    protected $dates = ['deleted_at'];
    protected $fillable = ['quantity', 'pva_pack_id', 'account_id', 'product_variation_attribute_id', 'statut'];

    // Define relationships or additional methods as needed

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    // Relation avec l'entrepôt parent (si applicable)
    public function parentPvaPack()
    {
        return $this->belongsTo(PvaPack::class, 'pva_pack_id');
    }


    // Relation avec les entrepôts enfants
    public function childPvaPacks()
    {
        return $this->hasMany(PvaPack::class, 'pva_pack_id');
    }
    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot('statut');
    }
    public function principalImage()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [2])
            ->withPivot('statut');
    }
    public function has_images()
    {
        return $this->hasMany(Image::class);
    }
    public function imageables()
    {
        return $this->hasMany(Imageable::class, 'imageable_id', 'id');
    }
}
