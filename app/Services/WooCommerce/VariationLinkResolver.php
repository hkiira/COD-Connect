<?php

namespace App\Services\WooCommerce;

use App\Models\ProductVariationAttribute;
use App\Models\WooCommerce\Store;
use App\Models\WooCommerce\VariationLink;

/**
 * Finds the CodConnect variation a WooCommerce line item stands for, through the links of one store.
 * Links copied from the old single-store data only know the variation id (a simple product kept its
 * product id in that column), so the lookup falls back to it.
 */
class VariationLinkResolver
{
    /** @var array<string, int|null> */
    private array $cache = [];

    public function __construct(private readonly Store $store)
    {
    }

    public function resolveId(int $wcProductId, int $wcVariationId): ?int
    {
        $key = "{$wcProductId}:{$wcVariationId}";
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $links = VariationLink::where('store_id', $this->store->id)
            ->where(fn ($q) => $q->where(fn ($exact) => $exact->where('wc_product_id', $wcProductId)->where('wc_variation_id', $wcVariationId))
                ->orWhere('wc_variation_id', $wcVariationId ?: $wcProductId))
            ->get();

        // exact (product, variation) first, the legacy variation-only link second
        $link = $links->first(fn ($l) => (int) $l->wc_product_id === $wcProductId && (int) $l->wc_variation_id === $wcVariationId)
            ?? $links->first();

        return $this->cache[$key] = $link?->product_variation_attribute_id;
    }

    public function resolve(int $wcProductId, int $wcVariationId): ?ProductVariationAttribute
    {
        $id = $this->resolveId($wcProductId, $wcVariationId);

        return $id ? ProductVariationAttribute::with(['product', 'variationAttribute.childVariationAttributes.attribute'])->find($id) : null;
    }
}
