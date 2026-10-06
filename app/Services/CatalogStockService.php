<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stock monitoring for the account: low-stock alerts with days of cover,
 * reconciliation of negative stock against the movement ledger, and audited
 * manual adjustments.
 */
class CatalogStockService
{
    /** Order statuses that represent real demand (not abandoned / out of stock / cancelled). */
    private const DEMAND_STATUSES = [1, 4, 5, 6, 7, 9, 10, 11];

    /** Human label of a variation: "size: 42 · color: black". */
    private const VARIATION_LABEL_SQL = "(SELECT GROUP_CONCAT(CONCAT(ta.title, ': ', a.title) ORDER BY ta.title SEPARATOR ' · ')
        FROM variation_attributes va
        JOIN attributes a ON a.id = va.attribute_id
        JOIN type_attributes ta ON ta.id = a.types_attribute_id
        WHERE va.variation_attribute_id = pva.variation_attribute_id)";

    // region alerts

    /**
     * Variations at or below their threshold, most urgent first.
     *
     * @return array{summary: array<string,int>, items: array<int,array<string,mixed>>}
     */
    public function alerts(int $accountId, int $limit = 200): array
    {
        $defaultThreshold = (int) config('inventory.default_low_stock_threshold', 10);
        $window = max(1, (int) config('inventory.velocity_window_days', 30));
        $statuses = implode(',', self::DEMAND_STATUSES);
        $label = self::VARIATION_LABEL_SQL;

        $rows = DB::table('product_variation_attribute as pva')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->leftJoin('warehouse_pva as w', 'w.product_variation_attribute_id', '=', 'pva.id')
            ->whereNull('pva.deleted_at')->whereNull('p.deleted_at')
            ->where('p.statut', 1)->where('pva.statut', 1)
            ->whereIn('p.id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $accountId))
            ->groupBy('pva.id', 'p.id', 'p.title', 'p.low_stock_threshold', 'pva.variation_attribute_id', 'pva.code')
            ->selectRaw("pva.id as pva_id, pva.code as pva_code, p.id as product_id, p.title as product_title,
                COALESCE(NULLIF(p.low_stock_threshold, 0), ?) as threshold,
                COALESCE(SUM(w.quantity), 0) as stock,
                $label as variation,
                (SELECT COALESCE(SUM(op.quantity), 0) FROM order_pva op JOIN orders o ON o.id = op.order_id
                    WHERE op.product_variation_attribute_id = pva.id AND op.deleted_at IS NULL AND o.deleted_at IS NULL
                    AND o.account_id = ? AND o.order_status_id IN ($statuses)
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL $window DAY)) as sold", [$defaultThreshold, $accountId])
            ->havingRaw('stock <= threshold')
            ->get();

        $items = $rows->map(function ($r) use ($window) {
            $velocity = round($r->sold / $window, 2);
            $stock = (float) $r->stock;

            return [
                'pva_id'           => $r->pva_id,
                'pva_code'         => $r->pva_code,
                'product_id'       => $r->product_id,
                'product_title'    => $r->product_title,
                'variation'        => $r->variation,
                'stock'            => $stock,
                'threshold'        => (int) $r->threshold,
                'daily_velocity'   => $velocity,
                'days_of_cover'    => $velocity > 0 && $stock > 0 ? round($stock / $velocity, 1) : null,
                'suggested_reorder' => (int) max(0, ceil($velocity * $window - max($stock, 0))),
                'level'            => $stock < 0 ? 'negative' : ($stock == 0 ? 'out' : 'low'),
            ];
        });

        $order = ['negative' => 0, 'out' => 1, 'low' => 2];
        $sorted = $items->sort(fn ($a, $b) => [$order[$a['level']], -$a['daily_velocity']] <=> [$order[$b['level']], -$b['daily_velocity']])->values();

        return [
            'summary' => [
                'negative' => $items->where('level', 'negative')->count(),
                'out'      => $items->where('level', 'out')->count(),
                'low'      => $items->where('level', 'low')->count(),
            ],
            'items' => $sorted->take($limit)->all(),
        ];
    }

    // endregion

    // region reconciliation

    /**
     * Negative stock rows with the movement ledger that produced them.
     * "entries" and "exits" come from the applied movement lines; whatever the
     * ledger cannot explain is what has to be received or adjusted.
     */
    public function reconciliation(int $accountId, int $limit = 100): array
    {
        $label = self::VARIATION_LABEL_SQL;

        $rows = DB::table('warehouse_pva as w')
            ->join('warehouses as wh', 'wh.id', '=', 'w.warehouse_id')
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'w.product_variation_attribute_id')
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->where('wh.account_id', $accountId)->whereNull('wh.deleted_at')
            ->where('w.quantity', '<', 0)
            ->orderBy('w.quantity')->limit($limit)
            ->selectRaw("w.id as warehouse_pva_id, w.warehouse_id, wh.title as warehouse, pva.id as pva_id, p.id as product_id,
                p.title as product_title, w.quantity as stock, $label as variation,
                (SELECT COALESCE(SUM(mp.stock_applied_quantity), 0) FROM mouvement_pva mp
                    WHERE mp.product_variation_attribute_id = pva.id AND mp.stock_to_warehouse_id = w.warehouse_id AND mp.deleted_at IS NULL) as entries,
                (SELECT COALESCE(SUM(mp.stock_applied_quantity), 0) FROM mouvement_pva mp
                    WHERE mp.product_variation_attribute_id = pva.id AND mp.stock_from_warehouse_id = w.warehouse_id AND mp.deleted_at IS NULL) as exits,
                (SELECT COALESCE(SUM(op.quantity), 0) FROM order_pva op JOIN orders o ON o.id = op.order_id
                    WHERE op.product_variation_attribute_id = pva.id AND o.account_id = ? AND o.order_status_id = 11
                    AND op.deleted_at IS NULL AND o.deleted_at IS NULL) as returned_units", [$accountId])
            ->get();

        $items = $rows->map(fn ($r) => [
            'warehouse_pva_id' => $r->warehouse_pva_id,
            'warehouse_id'     => $r->warehouse_id,
            'warehouse'        => $r->warehouse,
            'pva_id'           => $r->pva_id,
            'product_id'       => $r->product_id,
            'product_title'    => $r->product_title,
            'variation'        => $r->variation,
            'stock'            => (float) $r->stock,
            'entries'          => (float) $r->entries,
            'exits'            => (float) $r->exits,
            'returned_units'   => (float) $r->returned_units,
            // exits exceed what was ever received here: the missing quantity
            'missing'          => (float) max(0, -$r->stock),
        ]);

        return [
            'summary' => [
                'rows'          => $items->count(),
                'missing_units' => $items->sum('missing'),
                'returned_units' => $items->sum('returned_units'),
            ],
            'items' => $items->all(),
        ];
    }

    // endregion

    // region adjustment

    /**
     * Sets a warehouse stock line to a counted quantity and records who/why.
     *
     * @return array{old: float, new: float}
     */
    public function adjust(int $accountId, int $warehousePvaId, float $newQuantity, string $reason, ?int $accountUserId): array
    {
        return DB::transaction(function () use ($accountId, $warehousePvaId, $newQuantity, $reason, $accountUserId) {
            $line = DB::table('warehouse_pva as w')
                ->join('warehouses as wh', 'wh.id', '=', 'w.warehouse_id')
                ->where('w.id', $warehousePvaId)->where('wh.account_id', $accountId)
                ->lockForUpdate()
                ->first(['w.id', 'w.quantity', 'w.warehouse_id', 'w.product_variation_attribute_id']);

            if (! $line) {
                throw ValidationException::withMessages(['warehouse_pva_id' => 'Stock line not found for this account.']);
            }

            DB::table('warehouse_pva')->where('id', $line->id)->update(['quantity' => $newQuantity, 'updated_at' => now()]);

            DB::table('stock_adjustments')->insert([
                'account_id'                      => $accountId,
                'warehouse_id'                    => $line->warehouse_id,
                'product_variation_attribute_id'  => $line->product_variation_attribute_id,
                'account_user_id'                 => $accountUserId,
                'old_quantity'                    => $line->quantity,
                'new_quantity'                    => $newQuantity,
                'reason'                          => $reason,
                'created_at'                      => now(),
                'updated_at'                      => now(),
            ]);

            return ['old' => (float) $line->quantity, 'new' => $newQuantity];
        });
    }

    // endregion
}
