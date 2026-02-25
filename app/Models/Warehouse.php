<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Warehouse extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = ['code', 'title', 'warehouse_nature_id', 'warehouse_type_id', 'warehouse_id', 'account_id', 'statut'];

    // Relation avec la nature de l'entrepôt
    public function warehouse_nature()
    {
        return $this->belongsTo(WarehouseNature::class);
    }
    public function supplierOrders()
    {
        return $this->hasMany(SupplierOrder::class);
    }
    public function compensations()
    {
        return $this->morphToMany(Compensationable::class, 'compensationable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id', 1);
    }
    public function warehouse_type()
    {
        return $this->belongsTo(WarehouseType::class);
    }

    public function accounts()
    {
        return $this->belongsTo(Account::class);
    }

    public function suppliers()
    {
        return $this->belongsToMany(Supplier::class, 'warehouse_supplier')
            ->wherePivot('statut', 1);
    }

    public function users()
    {
        return $this->belongsToMany(AccountUser::class, 'warehouse_user')
            ->wherePivot('statut', 1);
    }
    // Relation avec l'entrepôt parent (si applicable)
    public function parentWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
    // Relation avec les entrepôts enfants
    public function childWarehouses()
    {
        return $this->hasMany(Warehouse::class, 'warehouse_id');
    }

    public function warehouseUsers()
    {
        return $this->hasMany(WarehouseUser::class);
    }
    public function products()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'warehouse_pva')
            ->withPivot('statut');
    }
    public function activePvas()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'warehouse_pva')
            ->wherePivotIn('statut', [1])
            ->withPivot('quantity');
    }
    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'warehouse_pva');
    }
    public function accountUsers()
    {
        return $this->belongsToMany(AccountUser::class, 'warehouse_user')
            ->wherePivot('statut', 1);
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
