<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashDrawer extends Model
{
    use SoftDeletes;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'code',
        'title',
        'description',
        'cash_drawer_id',
        'account_id',
        'account_user_id',
        'statut',
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }

    public function parentCashDrawer()
    {
        return $this->belongsTo(CashDrawer::class, 'cash_drawer_id');
    }

    public function childCashDrawers()
    {
        return $this->hasMany(CashDrawer::class, 'cash_drawer_id');
    }

    public function sessions()
    {
        return $this->hasMany(CashDrawerSession::class);
    }

    public function activeSession()
    {
        return $this->hasOne(CashDrawerSession::class)->where('statut', 1);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function calculateBalance()
    {
        $activeSession = $this->activeSession;
        if (!$activeSession) {
            return 0;
        }
        return $activeSession->calculateExpectedBalance();
    }
}
