<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraSyncRun extends Model
{
    protected $fillable = ['account_id', 'account_user_id', 'pickup_id', 'kind', 'status', 'processed', 'synchronized', 'skipped', 'failed', 'message'];
}
