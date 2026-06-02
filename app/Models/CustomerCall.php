<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CustomerCall extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'outcome', 'notes'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
