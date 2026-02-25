<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Traits\HasRoles;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mouvement extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'description',
        'from_warehouse',
        'to_warehouse',
        'from_nature',
        'to_nature',
        'account_user_id',
        'mouvement_type_id',
        'statut'
    ];

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse');
    }

    public function fromNature()
    {
        return $this->belongsTo(WarehouseNature::class, 'from_nature');
    }

    public function toNature()
    {
        return $this->belongsTo(WarehouseNature::class, 'to_nature');
    }
    public function mouvementPvas()
    {
        return $this->hasMany(MouvementPva::class, 'mouvement_id', 'id');
    }
    public function activeMouvementPvas()
    {
        return $this->hasMany(MouvementPva::class, 'mouvement_id', 'id')->whereNotIn('statut',[2,3]);
    }
    
    public function productVariationAttributes()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'mouvement_pva')
                ->withPivot(['quantity','price']);
    }
    
    public function activePvas()
    {
        return $this->belongsToMany(ProductVariationAttribute::class, 'mouvement_pva')
            ->withPivot( 'price', 'quantity');
    }
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function mouvementType()
    {
        return $this->belongsTo(MouvementType::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class, 'mouvement_id', 'id');
    }

    public function pickups()
    {
        return $this->hasMany(Inventory::class, 'mouvement_id', 'id');
    }

    public function supplierReceipts()
    {
        return $this->hasMany(Inventory::class, 'mouvement_id', 'id');
    }
}
