<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Phoneable extends Model
{
    protected $table = 'phoneables';
    use HasFactory;
    protected $fillable = [
        'phone_id',
        'phoneable_type',
        'phoneable_id',	
        'statut',	
    ];

    public function phone()
    {
        return $this->belongsTo(Phone::class);
    }

    // Define the morphTo relationship
    public function phoneable()
    {
        return $this->morphTo();
    }
}
