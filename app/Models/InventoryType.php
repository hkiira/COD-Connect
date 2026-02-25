<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class InventoryType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'inventory_types';
    protected $fillable = [
        'code',
        'title',
        'description',
        'statut'
    ];
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}
