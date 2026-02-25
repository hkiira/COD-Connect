<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class VariationAttribute extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = ['code', 'account_id', 'variation_attribute_id', 'attribute_id', 'statut'];

    // Define relationships or additional methods as needed

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    // Relation avec l'entrepôt parent (si applicable)
    public function parentVariationAttribute()
    {
        return $this->belongsTo(VariationAttribute::class, 'variation_attribute_id');
    }
    

    // Relation avec les entrepôts enfants
    public function childVariationAttributes()
    {
        return $this->hasMany(VariationAttribute::class, 'variation_attribute_id');
    }

    public function productVariationAttributes()
    {
        return $this->hasMany(ProductVariationAttribute::class, 'variation_attribute_id');
    }

    public function attribute()
    {
        return $this->belongsTo(Attribute::class, 'attribute_id');
    }
    public function attributes(){
        return $this->belongsToMany( Attribute::class, 'variation_attributes');
    }
    public function variation_product()
    {
        return $this->hasMany(ProductVariationAttribute::class);
        
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'product_variation_attribute');
    }
}
