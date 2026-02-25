<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OfferType extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'code','title','description', 'statut'
    ];

    protected $dates = ['deleted_at'];
    
    public function offers()
    {
        return $this->hasMany(Offer::class);
    }
}
