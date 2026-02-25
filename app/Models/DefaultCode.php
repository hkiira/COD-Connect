<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DefaultCode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'controller', 'prefix', 'counter', 'status'
    ];

    protected $dates = [
        'created_at', 'updated_at', 'deleted_at'
    ];

    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'account_codes');
    }
    
    public function accountCode()
    {
        return $this->belongsTo(AccountCode::class,'default_code_id');
    }
}
