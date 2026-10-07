<?php

namespace App\Models\WooCommerce;

use App\Models\Concerns\BelongsToAccount;
use App\Models\ProductVariationAttribute;
use Illuminate\Database\Eloquent\Model;

/** A WooCommerce product/variation of a store matched to a CodConnect product variation. */
class VariationLink extends Model
{
    use BelongsToAccount;

    protected $table = 'woocommerce_variation_links';

    protected $fillable = ['store_id', 'account_id', 'wc_product_id', 'wc_variation_id', 'product_variation_attribute_id', 'source'];

    public function store()
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    public function productVariationAttribute()
    {
        return $this->belongsTo(ProductVariationAttribute::class, 'product_variation_attribute_id');
    }
}
