<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Measurement extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code','title','measurement_id','statut'
    ];

    protected $dates = ['deleted_at'];
    
    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function measurements(){
        return $this->hasMany(Measurement::class);
    }
}
