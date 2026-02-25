<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class AccountCompensation extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code', 'title', 'compensation_id', 'account_user_id', 'statut', 'compensation_goal_id'
    ];
    public function compensationable()
    {
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id');
    }
    public function activeCompensationables()
    {
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id')
            ->where('statut', 1);
    }
    public function defaultSalary()
    {
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id')
            ->whereNull('compensationable_id')
            ->whereNull('compensationable_type')
            ->where('statut', 1);
    }
    public function defaultCompensations()
    {
        $toDayDate = now();
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id')
            ->whereNull('compensationable_id')
            ->whereNull('compensationable_type')
            ->where('statut', 1)
            ->where(function ($query) use ($toDayDate) {
                $query->where('start_date', '<', $toDayDate)
                    ->orWhereNull('start_date');
            })
            ->where(function ($query) use ($toDayDate) {
                $query->where('end_date', '>', $toDayDate)
                    ->orWhereNull('end_date');
            });
    }

    public function toCalculateCompensations()
    {
        $toDayDate = now();
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id')
            ->where(function ($query) {
                $query->whereNotNull('compensationable_type')
                    ->whereNotIn('compensationable_type', ['App\\Models\\Role', "App\\Models\\AccountUser"]);
            })
            ->where('statut', 1)
            ->where(function ($query) use ($toDayDate) {
                $query->where('start_date', '<', $toDayDate)
                    ->orWhereNull('start_date');
            })
            ->where(function ($query) use ($toDayDate) {
                $query->where('end_date', '>', $toDayDate)
                    ->orWhereNull('end_date');
            });
    }
    public function baseCalculateCompensations()
    {
        $toDayDate = now();
        return $this->hasMany(Compensationable::class, 'account_compensation_id', 'id')
            ->where(function ($query) {
                $query->whereNotNull('compensationable_type')
                    ->whereIn('compensationable_type', ['App\\Models\\Role', "App\\Models\\AccountUser"]);
            })
            ->where('statut', 1)
            ->where(function ($query) use ($toDayDate) {
                $query->where('start_date', '<', $toDayDate)
                    ->orWhereNull('start_date');
            })
            ->where(function ($query) use ($toDayDate) {
                $query->where('end_date', '>', $toDayDate)
                    ->orWhereNull('end_date');
            });
    }
    public function compensation()
    {
        return $this->belongsTo(Compensation::class);
    }
    public function compensationGoal()
    {
        return $this->belongsTo(CompensationGoal::class);
    }

    public function products()
    {
        return $this->morphedByMany(Product::class, 'compensationable');
    }
    public function activeProducts()
    {
        return $this->morphedByMany(Product::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function roles()
    {
        return $this->morphedByMany(Role::class, 'compensationable');
    }
    public function activeRoles()
    {
        return $this->morphedByMany(Role::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function brands()
    {
        return $this->morphedByMany(Brand::class, 'compensationable');
    }
    public function activeBrands()
    {
        return $this->morphedByMany(Brand::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function orderStatuses()
    {
        return $this->morphedByMany(OrderStatus::class, 'compensationable');
    }
    public function activeOrderStatuses()
    {
        return $this->morphedByMany(OrderStatus::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function sources()
    {
        return $this->morphedByMany(Source::class, 'compensationable');
    }
    public function activeSources()
    {
        return $this->morphedByMany(Source::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function brandSources()
    {
        return $this->morphedByMany(BrandSource::class, 'compensationable');
    }
    public function activeBrandSources()
    {
        return $this->morphedByMany(BrandSource::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function warehouses()
    {
        return $this->morphedByMany(Warehouse::class, 'compensationable');
    }
    public function activeWarehouses()
    {
        return $this->morphedByMany(Warehouse::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function taxonomies()
    {
        return $this->morphedByMany(Taxonomy::class, 'compensationable');
    }
    public function activeTaxonomies()
    {
        return $this->morphedByMany(Taxonomy::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function customers()
    {
        return $this->morphedByMany(Customer::class, 'compensationable');
    }
    public function activeCustomers()
    {
        return $this->morphedByMany(Customer::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function accountUsers()
    {
        return $this->morphedByMany(AccountUser::class, 'compensationable');
    }
    public function activeAccountUsers()
    {
        return $this->morphedByMany(AccountUser::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function customerTypes()
    {
        return $this->morphedByMany(CustomerType::class, 'compensationable');
    }
    public function activeCustomerTypes()
    {
        return $this->morphedByMany(CustomerType::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function cities()
    {
        return $this->morphedByMany(City::class, 'compensationable');
    }
    public function activeCities()
    {
        return $this->morphedByMany(City::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function countries()
    {
        return $this->morphedByMany(Country::class, 'compensationable');
    }
    public function activeCountries()
    {
        return $this->morphedByMany(Country::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function regions()
    {
        return $this->morphedByMany(Region::class, 'compensationable');
    }
    public function activeRegions()
    {
        return $this->morphedByMany(Region::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function sectors()
    {
        return $this->morphedByMany(Sector::class, 'compensationable');
    }
    public function activeSectors()
    {
        return $this->morphedByMany(Sector::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function attributes()
    {
        return $this->morphedByMany(Attribute::class, 'compensationable');
    }
    public function activeAttributes()
    {
        return $this->morphedByMany(Attribute::class, 'compensationable')
            ->wherePivot('statut', 1);
    }
    public function productVariationAttributes()
    {
        return $this->morphedByMany(ProductVariationAttribute::class, 'compensationable');
    }
    public function activePvas()
    {
        return $this->morphedByMany(ProductVariationAttribute::class, 'compensationable')
            ->wherePivot('statut', 1);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
}
