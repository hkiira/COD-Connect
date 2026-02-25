<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class Expense extends Model
{
    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected $fillable = [
        'id',
        'code',
        'description',
        'date',
        'amount',
        'account_user_id',
        'expense_type_id',
        'created_at',
        'updated_at',
        'statut'
    ];

    /**
     * Get the user that owns the region
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */

    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
    public function expenseType()
    {
        return $this->belongsTo(ExpenseType::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
}
