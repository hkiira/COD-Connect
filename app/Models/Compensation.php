<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Compensation extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'compensations';
    protected $fillable = [
        'code',
        'title',
        'description',
        'compensation_type_id',
        'compensation_goal_id',
        'statut'
    ];
    public function accountCompensations()
    {
        return $this->hasMany(AccountCompensation::class);
    }

    public function compensationType()
    {
        return $this->belongTo(CompensationType::class);
    }
    public function compensationGoal()
    {
        return $this->belongTo(compensationGoal::class);
    }
}
