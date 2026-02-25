<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Category extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    
    protected $fillable = [
        'title' ,
        'statut' ,
        'photo' , 
        'photo_dir',
        'account_user_id',
        'category_id',
    ];
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function parentCategory()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subcategories()
    {
        return $this->hasMany(Category::class, 'category_id');
    }
    public function products()
    {
        return $this->belongsToMany(Product::class , 'account_product', 'category_id', 'product_id', 'id');
    }
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->wherePivotIn('statut', [1,2])
            ->withPivot('statut');
    }
    public function imageables()
    {

        return $this->hasMany(Imageable::class, 'imageable_id', 'id');
    }
}
