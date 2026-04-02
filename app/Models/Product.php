<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Product extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'reference',
        'code',
        'meta',
        'statut',
        'title',
        'link',
        'price',
        'sellingprice',
        'account_user_id',
        'account_id',
        'product_type_id',
    ];
    protected $casts = [
        'meta' => 'array', // Auto-casts JSON to an array
    ];

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    public function productVariationAttributes()
    {
        return $this->hasMany(ProductVariationAttribute::class);
    }

    public function activePvas()
    {
        return $this->hasMany(ProductVariationAttribute::class)
            ->where('statut', '!=', 0);
    }


    public function price()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->where(['offer_type_id' => 1, 'offers.statut' => 1]);
    }

    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->withPivot('id')
            ->whereNot('offer_type_id', 1);
    }
    public function accountCompensations()
    {
        return $this->morphToMany(AccountCompensation::class, 'compensationable');
    }
    public function supplierPvas()
    {
        return $this->hasMany(SupplierPva::class);
    }

    public function pvaMeasurements()
    {
        return $this->belongsToMany(PvaMeasurement::class, 'product_variation_attribute');
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class);
    }


    public function accountProducts()
    {
        return $this->hasMany(AccountProduct::class);
    }


    public function taxonomyProducts()
    {
        return $this->belongsToMany(TaxonomyProduct::class, 'account_product');
    }

    public function brandSources()
    {
        return $this->belongsToMany(BrandSource::class, 'product_brand_source');
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
