<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Offer extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at','started','expired'];
    protected $fillable = [
        'code','title','started','expired', 'price', 'shipping_price', 'statut','account_id','statut','account_id','discount','offer_type_id'
    ];
    public function offerable()
    {
        return $this->hasMany(Offerable::class, 'offer_id', 'id');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [1,2])
            ->withPivot('statut');
    }
    public function products()
    {
        return $this->morphedByMany(Product::class,'offerable')->withPivot('id');
    }
    public function productVariationAttributes()
    {
        return $this->morphedByMany(ProductVariationAttribute::class,'offerable')->withPivot('id');
    }
    public function brands()
    {
        return $this->morphedByMany(Brand::class,'offerable')->withPivot('id');
    }
    public function sources()
    {
        return $this->morphedByMany(Source::class,'offerable')->withPivot('id');
    }
    public function brandSources()
    {
        return $this->morphedByMany(BrandSource::class,'offerable')->withPivot('id');
    }
    public function warehouses()
    {
        return $this->morphedByMany(Warehouse::class,'offerable')->withPivot('id');
    }
    public function taxonomies()
    {
        return $this->morphedByMany(Taxonomy::class,'offerable')->withPivot('id');
    }
    public function customers()
    {
        return $this->morphedByMany(Customer::class,'offerable')->withPivot('id');
    }
    public function customerTypes()
    {
        return $this->morphedByMany(CustomerType::class,'offerable')->withPivot('id');
    }
    public function cities()
    {
        return $this->morphedByMany(City::class,'offerable')->withPivot('id');
    }
    public function countries()
    {
        return $this->morphedByMany(Country::class,'offerable')->withPivot('id');
    }
    public function regions()
    {
        return $this->morphedByMany(Region::class,'offerable')->withPivot('id');
    }
    public function sectors()
    {
        return $this->morphedByMany(Sector::class,'offerable')->withPivot('id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    public function offerType()
    {
        return $this->belongsTo(OfferType::class);
    }


    
    
}
