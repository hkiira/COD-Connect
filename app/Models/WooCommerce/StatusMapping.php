<?php

namespace App\Models\WooCommerce;

use Illuminate\Database\Eloquent\Model;

/** Which WooCommerce status a CodConnect order status is pushed as, for one store. */
class StatusMapping extends Model
{
    protected $table = 'woocommerce_status_mappings';

    protected $fillable = ['store_id', 'order_status_id', 'wc_status', 'is_enabled'];

    protected $casts = ['is_enabled' => 'boolean'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }
}
