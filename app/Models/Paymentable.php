<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Paymentable extends Model
{
    protected $table = 'paymentables';
    use HasFactory;
    protected $fillable = [
        'account_user_id',
        'paymentable_id',
        'paymentable_type',
        'payment_id',
        'amount',
        'commission',
        'compensationable_id',
        'created_at',
        'updated_at',
        'statut',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function compensationable()
    {
        return $this->belongsTo(Compensationable::class);
    }
    public function accountUserOrderStatus()
    {
        return $this->belongsTo(AccountUserOrderStatus::class, 'paymentable_id', 'id');
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    // Define the morphTo relationship
    public function paymentable()
    {
        return $this->morphTo();
    }
}
