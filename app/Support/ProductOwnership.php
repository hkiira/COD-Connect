<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Products are shared rows linked to accounts through account_product (and created by an
 * account user). A product belongs to the current account when either link exists.
 */
class ProductOwnership
{
    public static function owns(int|string|null $productId, ?int $accountId = null): bool
    {
        if (! $productId || ! is_numeric($productId)) {
            return false;
        }

        $accountId ??= (int) getAccountUser()?->account_id;
        if (! $accountId) {
            return false;
        }

        return DB::table('account_product')
                ->where('account_id', $accountId)->where('product_id', $productId)->exists()
            || DB::table('products')
                ->where('id', $productId)
                ->whereIn('account_user_id', fn ($q) => $q->select('id')->from('account_user')->where('account_id', $accountId))
                ->exists();
    }

    /** Create payloads use the "...ToActive" keys the update screen sends; store() reads the plain keys. */
    public static function normalizeCreatePayload(array $products): array
    {
        $map = [
            'brandsToActive'     => 'brands',
            'taxonomiesToActive' => 'categories',
            'warehousesToActive' => 'warehouses',
            'offersToActive'     => 'offers',
            'suppliersToActive'  => 'suppliers',
        ];

        return array_map(function ($product) use ($map) {
            if (! is_array($product)) {
                return $product;
            }

            foreach ($map as $from => $to) {
                if (isset($product[$from]) && ! isset($product[$to])) {
                    $product[$to] = $product[$from];
                }
                unset($product[$from]);
            }

            // nothing to deactivate on a brand-new product
            unset(
                $product['brandsToInactive'],
                $product['taxonomiesToInactive'],
                $product['warehousesToInactive'],
                $product['offersToInactive'],
                $product['suppliersToInactive']
            );

            return $product;
        }, $products);
    }
}
