<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class TransactionType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'transaction_types';
    protected $fillable = [
        'title',
        'code',
        'description',
        'statut'
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
