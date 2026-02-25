<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes; // Import the SoftDeletes trait

class ExpenseType extends Model
{
    protected $dates = ['deleted_at'];
    use SoftDeletes;
    use HasFactory;
    protected $table = 'expense_types';
    protected $fillable = [
        'code',
        'title',
        'statut',
        'created_at',
        'updated_at',
        'account_user_id',
    ];

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }
    public function accountUser()
    {
        return $this->belongsTo(AccountUser::class);
    }
}
