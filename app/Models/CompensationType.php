<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class CompensationType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'compensation_types';
    protected $fillable = [
        'code',
        'title',
        'description',
        'statut'
    ];
    public function compensations()
    {
        return $this->hasMany(Compensation::class);
    }
    public function commissionCategory()
    {
        return $this->belongsTo(CommissionCategory::class);
    }
}
