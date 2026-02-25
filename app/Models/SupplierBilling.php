<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SupplierBilling extends Model
{
    use HasFactory;

    public function suppliers()
    {
        return $this->belongsTo(Supplier::class);
        
    }

    public function account_users()
    {
        return $this->belongsTo(AccountUser::class);
        
    }
}
