<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Optional guard against outgoing stock movements that exceed what the source
 * warehouse holds (config inventory.block_negative_stock).
 */
class StockGuard
{
    /** Error message when the movement would make stock negative, else null. */
    public static function insufficient(?int $warehouseId, int $pvaId, float $quantity): ?string
    {
        if (! config('inventory.block_negative_stock') || ! $warehouseId) {
            return null;
        }

        $available = (float) DB::table('warehouse_pva')
            ->where('warehouse_id', $warehouseId)
            ->where('product_variation_attribute_id', $pvaId)
            ->sum('quantity');

        return $quantity > $available
            ? "Insufficient stock: {$available} available, {$quantity} requested."
            : null;
    }
}
