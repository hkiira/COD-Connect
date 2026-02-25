<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class PaymentMethod extends Model
{
    use HasFactory;
    protected $dates = ['deleted_at'];
    use SoftDeletes;
    protected $fillable = [
        'code',
        'title'
    ];

    public function paymentCommissions()
    {
        return $this->hasMany(PaymentCommission::class);
    }

    public function orders(){
        return $this->hasMany(Order::class);
    }

}
