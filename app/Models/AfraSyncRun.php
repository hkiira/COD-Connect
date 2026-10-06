<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AfraSyncRun extends Model
{
    /** A queued/running run with no progress for this long is considered dead (worker killed). */
    public const STALE_MINUTES = 30;

    protected $fillable = ['account_id', 'account_user_id', 'pickup_id', 'kind', 'status', 'processed', 'synchronized', 'skipped', 'failed', 'message'];

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subMinutes(self::STALE_MINUTES));
    }
}
