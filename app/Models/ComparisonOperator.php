<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class ComparisonOperator extends Model
{
    protected $dates = ['deleted_at'];
    use SoftDeletes;
    use HasFactory;
    protected $table = 'comparison_operators';
    protected $fillable = [
        'title',
        'symbol',
        'description',
        'statut',
    ];

    public function compensationables()
    {
        return $this->hasMany(Compensationable::class);
    }
}
