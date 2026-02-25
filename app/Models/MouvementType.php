<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class MouvementType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'mouvement_types';
    protected $fillable = [
        'title',
    ];


    public function attributes()
    {
        return $this->hasMany(Mouvement::class,'mouvement_type_id', 'id');
    }
}
