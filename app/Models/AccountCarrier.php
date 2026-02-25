<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class AccountCarrier extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'autocode',
        'account_id',
        'carrier_id',
        'username',
        'password',
        'token',
        'statut'
    ];
    protected $table = 'account_carrier' ;


    public function carrier(){
        return $this->belongsTo(Carrier::class);
    }

    public function account(){
        return $this->belongsTo(Account::class);
    }

    public function accountCity()
    {
        return $this->belongsToMany(AccountCity::class, 'account_carrier_city');
        
    }

    public function accountCarrierCity()
    {
        return $this->hasMany(AccountCarrierCity::class);
        
    }

    public function pickups()
    {
        return $this->hasMany(Pickup::class);
    }

    public function account_user()
    {
        return $this->belongsToMany(AccountUser::class, 'pickups')
        ->withPivot('code')
        ;
    }

    public function collectors()
    {
        return $this->belongsToMany(Collector::class, 'pickups')
        ->withPivot('code');

    }

    public function invoices(){
        $this->hasMany(Invoice::class);
    }

    public function user_invoice(){
        $this->belongsToMany(User::class, 'invoices');
    }
}
