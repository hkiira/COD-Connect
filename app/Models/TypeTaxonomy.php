<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TypeTaxonomy extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'statut'
    ];

    protected $dates = ['deleted_at'];
    
    public function taxonomies()
    {
        return $this->hasMany(Taxonomy::class);
    }
}
