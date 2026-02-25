<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class PhoneTypes extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'title',
        'statut',
        'account_id'
    ];
    
    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
}
