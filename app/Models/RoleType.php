<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class RoleType extends Model
{
    protected $dates = ['deleted_at'];
    use SoftDeletes;
    use HasFactory;
    protected $table = 'role_types';
    protected $fillable = [
        'title',
        'statut',
    ];

    public function roles()
    {
        return $this->hasMany(Role::class);
    }
}
