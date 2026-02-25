<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Imageable extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'image_id',
        'statut',
        'imageable_id',
        'imageable_type'
    ];
    public function brands(){
        return $this->belongsTo(Brand::class, 'imageable_id','id');
    }
}
