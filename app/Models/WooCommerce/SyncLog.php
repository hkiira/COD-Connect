<?php

namespace App\Models\WooCommerce;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

/** One line of the WooCommerce activity log (imports, status pushes, variation matches). */
class SyncLog extends Model
{
    use BelongsToAccount;

    public const UPDATED_AT = null;

    protected $table = 'woocommerce_sync_logs';

    protected $fillable = ['store_id', 'account_id', 'direction', 'entity', 'entity_id', 'wc_id', 'status', 'message', 'payload'];

    protected $casts = ['payload' => 'array'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public static function record(Store $store, string $direction, string $entity, string $status, ?string $message = null, array $extra = []): self
    {
        return static::create([
            'store_id' => $store->id,
            'account_id' => $store->account_id,
            'direction' => $direction,
            'entity' => $entity,
            'status' => $status,
            'message' => $message ? mb_substr($message, 0, 1000) : null,
            'entity_id' => $extra['entity_id'] ?? null,
            'wc_id' => $extra['wc_id'] ?? null,
            'payload' => $extra['payload'] ?? null,
        ]);
    }
}
