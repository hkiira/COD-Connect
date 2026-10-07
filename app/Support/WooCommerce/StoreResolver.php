<?php

namespace App\Support\WooCommerce;

use App\Models\WooCommerce\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Finds the store a request is about. The Store model is account scoped, so a store of another
 * account simply does not exist for the caller (404).
 */
class StoreResolver
{
    /**
     * @param int|string|null $storeId explicit store; null falls back to the account's only (or first active) store,
     *                                  which keeps the screens that predate multi-store working
     * @throws ModelNotFoundException
     */
    public static function resolve(int|string|null $storeId = null): Store
    {
        if ($storeId !== null && $storeId !== '') {
            return Store::findOrFail($storeId);
        }

        $store = Store::where('is_active', true)->orderBy('id')->first() ?? Store::orderBy('id')->first();

        if (! $store) {
            throw (new ModelNotFoundException())->setModel(Store::class);
        }

        return $store;
    }
}
