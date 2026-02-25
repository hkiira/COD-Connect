<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Addressable extends Model
{
    protected $table = 'addressables';
    use HasFactory;
    protected $fillable = [
        'address_id',
        'addressable_type',
        'addressable_id',	
        'statut',	
    ];

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    // Define the morphTo relationship
    public function addressable()
    {
        return $this->morphTo();
    }
}
