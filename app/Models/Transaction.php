<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'code',
        'cash_drawer_id',
        'cash_drawer_session_id',
        'transaction_id',
        'title',
        'notes',
        'amount',
        'direction',
        'category',
        'transaction_type',
        'transaction_type_id',
        'account_user_id',
        'supplier_id',
        'carrier_id',
        'created_at',
        'updated_at',
        'statut',
    ];
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function carrier()
    {
        return $this->belongsTo(Carrier::class);
    }

    public function accountCarrier()
    {
        return $this->belongsTo(AccountCarrier::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    
    public function expense()
    {
        return $this->belongsTo(Expense::class, 'transaction_id', 'id');
    }
    public function transactionType()
    {
        return $this->belongsTo(TransactionType::class);
    }
    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class);
    }

    public function cashDrawerSession()
    {
        return $this->belongsTo(CashDrawerSession::class);
    }
}
