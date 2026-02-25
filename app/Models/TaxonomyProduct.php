<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxonomyProduct extends Model
{
    use HasFactory, SoftDeletes;
    protected $table = 'taxonomy_product';

    protected $fillable = [
        'account_product_id', 'taxonomy_id', 'statut'
    ];

    protected $dates = ['deleted_at'];


    public function taxonomy()
    {
        return $this->belongsTo(Taxonomy::class);
    }
    public function accountProduct()
    {
        return $this->belongsTo(AccountProduct::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
}
