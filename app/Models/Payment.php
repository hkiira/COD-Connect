<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Payment extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];

    protected $fillable = [
        'id',
        'code',
        'description',
        'payment_id',
        'date_debut',
        'date_end',
        'payment_method_id',
        'account_user_id',
        'created_at',
        'updated_at',
        'statut',
    ];
    public function parentPayment()
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
    // Relation avec les payments enfants
    public function childPayments()
    {
        return $this->hasMany(Payment::class, 'payment_id');
    }
    public function paymentables()
    {
        return $this->hasMany(Paymentable::class, 'payment_id');
    }
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class);
    }
    public function orderPvas()
    {
        return $this->morphedByMany(OrderPva::class, 'paymentable');
    }

    public function orders()
    {
        return $this->morphedByMany(Order::class, 'paymentable');
    }

    public function accountUserOrderStatus()
    {
        return $this->morphedByMany(AccountUserOrderStatus::class, 'paymentable');
    }
}
