<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Image extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'account_id',
        'type',
        'title',
        'description',
        'metadata',
        'photo',
        'image_type_id',
        'photo_dir',
        'statut'
    ];

    public function imageType()
    {
        return $this->belongsTo(ImageType::class);
    }
    public function accounts()
    {
        return $this->morphedByMany(Account::class, 'imageable');
    }
    public function imageables()
    {
        return $this->hasMany(Imageable::class, 'image_id', 'id');
    }

    public function users()
    {
        return $this->morphedByMany(User::class, 'imageable');
    }
    public function taxonomies()
    {
        return $this->morphedByMany(Taxonomy::class, 'imageable');
    }

    public function phoneTypes()
    {
        return $this->morphedByMany(PhoneTypes::class, 'imageable');
    }

    public function countries()
    {
        return $this->morphedByMany(Country::class, 'imageable');
    }
    public function offers()
    {
        return $this->morphedByMany(Offer::class, 'imageable');
    }
    
    public function productVariationAttributes()
    {
        return $this->morphedByMany(ProductVariationAttribute::class, 'imageable');
    }

    public function attributes()
    {
        return $this->morphedByMany(Attribute::class, 'imageable');
    }
    public function productType()
    {
        return $this->morphedByMany(productType::class, 'imageable');
    }

    public function suppliers()
    {
        return $this->morphedByMany(Supplier::class, 'imageable');
    }
    public function carriers()
    {
        return $this->morphedByMany(Carrier::class, 'imageable');
    }

    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'imageable');
    }

    public function collectors()
    {
        return $this->morphedByMany(Collector::class, 'imageable');
    }

    public function brands()
    {
        return $this->morphedByMany(Brand::class, 'imageable');
    }

    public function warehouses()
    {
        return $this->morphedByMany(Warehouse::class, 'imageable');
    }

    public function sources()
    {
        return $this->morphedByMany(Source::class, 'imageable');
    }

    public function pvaPacks()
    {
        return $this->morphedByMany(PvaPack::class, 'imageable');
    }
}
