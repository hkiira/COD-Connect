<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class PhoneType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'phone_id',
        'phone_type_id',
        'statut',
        'account_id'
    ];
    public function phone(){
        return $this->belongsTo(Phone::class);
    }
    public function phoneTypes(){
        return $this->belongsTo(PhoneTypes::class);
    }
}
