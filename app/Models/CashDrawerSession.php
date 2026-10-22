<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashDrawerSession extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code',
        'cash_drawer_id',
        'account_user_id',
        'opening_balance',
        'closing_balance',
        'expected_balance',
        'difference',
        'opened_at',
        'closed_at',
        'notes',
        'statut',
    ];

    protected $casts = [
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'expected_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function cashDrawer()
    {
        return $this->belongsTo(CashDrawer::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'cash_drawer_session_id');
    }

    public function calculateExpectedBalance()
    {
        $totalIn = $this->transactions()->where('direction', 'in')->sum('amount');
        $totalOut = $this->transactions()->where('direction', 'out')->sum('amount');

        return $this->opening_balance + $totalIn - $totalOut;
    }

    public function isOpen()
    {
        return $this->statut === 1 && $this->closed_at === null;
    }
}
