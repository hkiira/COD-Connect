<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class CompensationGoal extends Model
{
    protected $dates = ['deleted_at'];
    use SoftDeletes;
    use HasFactory;
    protected $table = 'compensation_goals';
    protected $fillable = [
        'title',
        'statut',
    ];

    public function accountCompensations()
    {
        return $this->hasMany(AccountCompensation::class);
    }
}
