<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class CustomerType extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $table = 'customer_types';
    protected $fillable = [
        'code',
        'title',
        'description',
        'account_user_id',
        'statut'
    ];

    public function accounts()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function commissions()
    {
        return $this->morphToMany(Commission::class, 'commissionable');
    }
    public function offers()
    {
        return $this->morphToMany(Offer::class, 'offerable')
            ->whereNot('offer_type_id',1);
    }
    public function customers()
    {
        return $this->hasMany(Customer::class,'customer_type_id', 'id');
    }
}
