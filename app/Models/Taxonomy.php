<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Taxonomy extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'taxonomies';

    protected $fillable = [
        'code','title', 'description', 'account_user_id', 'type_taxonomy_id','taxonomy_id', 'statut'
    ];

    protected $dates = ['deleted_at'];
    
    public function parentTaxonomy()
    {
        return $this->belongsTo(Taxonomy::class, 'taxonomy_id');
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
    public function childTaxonomies()
    {
        return $this->hasMany(Taxonomy::class, 'taxonomy_id');
    }

    public function typeTaxonomy()
    {
        return $this->belongsTo(TypeTaxonomy::class, 'type_taxonomy_id');
    }
    
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class, 'account_user_id');
    }

    public function taxonomyProducts()
    {
        return $this->hasMany(TaxonomyProduct::class);
    }
    
    public function products()
    {
        return $this->belongsToMany(AccountProduct::class , 'taxonomy_product', 'taxonomy_id', 'account_product_id', 'id');
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
