<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Depot extends Model
{
    use HasFactory;
    //hadi bach nqder n ajouter plusieur depot f fonction create_new_account
    protected $guarded = [];   

    public function accounts()
    {
        return $this->belongsTo(Account::class);
    }
}
