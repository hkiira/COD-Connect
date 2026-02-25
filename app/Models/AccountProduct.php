<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountProduct extends Model
{
    use HasFactory;

    protected $table = 'account_product' ;
    protected $fillable = [
        'product_id',
        'account_id',
        'statut'
    ];


    public function account(){
        return $this->belongsTo(Account::class);
    }
    public function product(){
        return $this->belongsTo(Product::class);
    }

    public function taxonomies()
    {
        return $this->belongsToMany(Taxonomy::class , 'taxonomy_product');
    }

}
