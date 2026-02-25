<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;

class Account extends Model
{
    use HasFactory;
    use HasRoles;
    protected $guard_name = "api";
    protected $fillable = [
        'code',
        'name',
        'statut',
    ];

    // define relation between country, regions, cities, sectors 
    
    public function cities()
    {
        return $this->morphedByMany(City::class,'locationable', 'account_locations','account_id','locationable_id');
    }
    public function countries()
    {
        return $this->morphedByMany(Country::class,'locationable', 'account_locations','account_id','locationable_id');
    }

    public function regions()
    {
        return $this->morphedByMany(Region::class,'locationable', 'account_locations','account_id','locationable_id');
    }
    
    public function orders()
    {
        return $this->hasMany(Order::class, 'account_id', 'id');
    }
    public function sectors()
    {
        return $this->morphedByMany(Sector::class,'locationable', 'account_locations','account_id','locationable_id');
    }
    //define relation with carriers model
    public function carriers()
    {
        return $this->belongsToMany(Carrier::class, 'account_carrier');
    }
    public function has_carriers()
    {
        return $this->hasMany(Carrier::class);
    }
    public function modelRoles()
    {
        return $this->belongsToMany(Role::class, 'model_has_roles', 'model_id', 'role_id');
    }
    public function accountUsers()
    {
        return $this->hasMany(AccountUser::class);
    }
    public function defaultCodes()
    {
        return $this->belongsToMany(DefaultCode::class, 'account_codes');
    }
    public function users()
    {
        return $this->belongsToMany(User::class, 'account_user');
    }
    public function products()
    {
        return $this->belongsToMany(Product::class, 'account_product')
            ->withPivot('id');
    }
    public function brand_offers()
    {
        return $this->belongsToMany(Brand::class, 'offers');
    }

    // morphToMany
    public function phones()
    {
        return $this->morphToMany(Phone::class, 'phoneable');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
    public function hasImages()
    {
        return $this->hasMany(Image::class, 'account_id');
    }

    public function addresses()
    {
        return $this->morphToMany(Address::class, 'addressable');
    }

    // hasMany
    public function account_carriers()
    {
        return $this->hasMany(AccountCarrier::class);
    }

    public function account_codes()
    {
        return $this->hasMany(AccountCode::class);
    }
    
    public function account_product()
    {
        return $this->hasMany(AccountProduct::class);
    }
    public function account_city()
    {
        return $this->hasMany(AccountCity::class);
    }
    public function phone_types()
    {
        return $this->hasMany(AccountUser::class);
    }
    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function depots()
    {
        return $this->hasMany(Depot::class);
    }
    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }
    public function offers()
    {
        return $this->hasMany(Offer::class);
    }

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function collectors()
    {
        return $this->hasMany(Collector::class);
    }
    public function brands()
    {
        return $this->hasMany(Brand::class);
    }
    public function sources()
    {
        return $this->hasMany(Source::class);
    }
    
}
