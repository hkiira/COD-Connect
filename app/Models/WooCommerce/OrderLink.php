<?php

namespace App\Models\WooCommerce;

use App\Models\Concerns\BelongsToAccount;
use App\Models\Order;
use Illuminate\Database\Eloquent\Model;

/** A CodConnect order created from (or tied to) a WooCommerce order of a store. */
class OrderLink extends Model
{
    use BelongsToAccount;

    protected $table = 'woocommerce_order_links';

    protected $fillable = [
        'store_id', 'account_id', 'wc_order_id', 'order_id', 'imported_at',
        'last_wc_status', 'last_pushed_status', 'last_pushed_at',
    ];

    protected $casts = ['imported_at' => 'datetime', 'last_pushed_at' => 'datetime'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
