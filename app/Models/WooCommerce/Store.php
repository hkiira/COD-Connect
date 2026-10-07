<?php

namespace App\Models\WooCommerce;

use App\Casts\EncryptedCredential;
use App\Models\Concerns\BelongsToAccount;
use App\Support\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One WooCommerce website connected to an account. An account can have several.
 * The API keys are encrypted at rest and never serialized.
 */
class Store extends Model
{
    use BelongsToAccount;
    use SoftDeletes;

    protected $table = 'woocommerce_stores';

    protected $fillable = [
        'account_id', 'name', 'base_url', 'consumer_key', 'consumer_secret', 'verify_ssl', 'ca_bundle',
        'is_active', 'default_warehouse_id', 'default_brand_source_id', 'import_statuses', 'auto_import',
        'wc_status_after_import', 'last_checked_at', 'last_error',
    ];

    protected $hidden = ['consumer_key', 'consumer_secret'];

    protected $casts = [
        'consumer_key' => EncryptedCredential::class,
        'consumer_secret' => EncryptedCredential::class,
        'verify_ssl' => 'boolean',
        'is_active' => 'boolean',
        'auto_import' => 'boolean',
        'import_statuses' => 'array',
        'last_checked_at' => 'datetime',
    ];

    /** CodConnect status => [WooCommerce status, enabled]. Pushing a status back to the store is opt-in. */
    public const DEFAULT_MAPPINGS = [
        OrderStatus::CONFIRMED => ['processing', false],
        OrderStatus::IN_DELIVERY => ['on-hold', false],
        OrderStatus::DELIVERED => ['completed', true],
        OrderStatus::CANCELLED => ['cancelled', true],
        OrderStatus::PAID => ['completed', true],
        OrderStatus::RETURNED => ['refunded', false],
    ];

    /** Creates the missing default mappings of the store (existing ones are left as the user set them). */
    public function seedDefaultMappings(): void
    {
        foreach (self::DEFAULT_MAPPINGS as $statusId => [$wcStatus, $enabled]) {
            StatusMapping::firstOrCreate(
                ['store_id' => $this->id, 'order_status_id' => $statusId],
                ['wc_status' => $wcStatus, 'is_enabled' => $enabled]
            );
        }
    }

    /** The URL as the API client needs it: no trailing slash. */
    public function apiUrl(): string
    {
        return rtrim((string) $this->base_url, '/');
    }

    /** Public shape sent to the frontend: no secret, only whether one is set. */
    public function toPanelArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'verify_ssl' => $this->verify_ssl,
            'is_active' => $this->is_active,
            'has_credentials' => (bool) ($this->consumer_key && $this->consumer_secret),
            'default_warehouse_id' => $this->default_warehouse_id,
            'default_brand_source_id' => $this->default_brand_source_id,
            'import_statuses' => $this->import_statuses ?? ['processing'],
            'auto_import' => $this->auto_import,
            'wc_status_after_import' => $this->wc_status_after_import,
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_error' => $this->last_error,
        ];
    }

    public function variationLinks()
    {
        return $this->hasMany(VariationLink::class, 'store_id');
    }

    public function orderLinks()
    {
        return $this->hasMany(OrderLink::class, 'store_id');
    }

    public function statusMappings()
    {
        return $this->hasMany(StatusMapping::class, 'store_id');
    }

    public function logs()
    {
        return $this->hasMany(SyncLog::class, 'store_id');
    }
}
