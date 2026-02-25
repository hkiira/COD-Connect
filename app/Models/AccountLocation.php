<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountLocation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'account_id',
        'locationable_type',
        'locationable_id',
        'statut',
    ];

}