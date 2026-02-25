<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class WarehouseSupplier extends Model
{
    protected $table = 'warehouse_supplier';
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'supplier_id',
        'warehouse_id',
        'statut'
    ];
    
    public function suppliers(){
        return $this->belongsTo(Supplier::class);
    }
    public function warehouses(){
        return $this->belongsTo(Warehouse::class);
    }
}
