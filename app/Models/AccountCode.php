<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccountCode extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'controleur',
        'account_id',
        'default_code_id',
        'prefixe',
        'counter'
    ];
    protected $guarded = [];   

    public function account(){
        return $this->belongsTo(Account::class);
    }
    
}
