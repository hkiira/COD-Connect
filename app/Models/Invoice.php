<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'customer_id',
        'title',
        'statut',
        'account_user_id',
    ];    public function account_carrier(){
        $this->belongsTo(AccountCarrier::class);
    }

    public function accountUser(){
        $this->belongsTo(AccountUser::class);
    }

    public function customer(){
        $this->belongsTo(Customer::class);
    }

    public function orders(){
        return $this->hasMany(Order::class);
    }

}
