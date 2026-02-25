<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class typeAttribute extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'type_attributes';
    protected $fillable = [
        'title',
        'code',
        'meta',
        'description',
        'account_user_id',
        'statut'
    ];
    protected $casts = [
        'meta' => 'array', // Auto-casts JSON to an array
    ];

    public function accounts()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function attributes()
    {
        return $this->hasMany(Attribute::class, 'types_attribute_id', 'id');
    }
}
