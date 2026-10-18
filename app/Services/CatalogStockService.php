<?php

namespace App\Services;

use App\Http\Controllers\DefaultCodeController;
use App\Models\Mouvement;
use App\Models\MouvementPva;
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
     * Integrates with Supplier Procurement FIFO logic by tracking open SupplierOrders
     * (statut 1 = En attente, 2 = Partiellement reçue) and subtracting pending order
     * quantities from suggested reorder amounts.
     *
     * @return array{summary: array<string,int|float>, items: array<int,array<string,mixed>>}
     */
    public function alerts(int $accountId, int $limit = 200, ?int $windowDays = null, ?int $targetCoverDays = null): array
    {
        $defaultThreshold = (int) config('inventory.default_low_stock_threshold', 10);
        $window = max(1, $windowDays ?? (int) config('inventory.velocity_window_days', 30));
        $coverDays = max(1, $targetCoverDays ?? $window);
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
                COALESCE(
                    (SELECT s.id FROM suppliers s JOIN supplier_pva sp ON sp.supplier_id = s.id WHERE sp.product_variation_attribute_id = pva.id AND sp.statut = 1 AND s.account_id = ? ORDER BY sp.id DESC LIMIT 1),
                    (SELECT so.supplier_id FROM supplier_order_pva sop JOIN supplier_orders so ON so.id = sop.supplier_order_id JOIN suppliers s ON s.id = so.supplier_id WHERE sop.product_variation_attribute_id = pva.id AND sop.deleted_at IS NULL AND so.deleted_at IS NULL AND s.account_id = ? ORDER BY sop.id DESC LIMIT 1),
                    (SELECT sr.supplier_id FROM supplier_order_pva sop JOIN supplier_receipts sr ON sr.id = sop.supplier_receipt_id JOIN suppliers s ON s.id = sr.supplier_id WHERE sop.product_variation_attribute_id = pva.id AND sop.deleted_at IS NULL AND sr.deleted_at IS NULL AND s.account_id = ? ORDER BY sop.id DESC LIMIT 1)
                ) as supplier_id,
                COALESCE(
                    (SELECT s.title FROM suppliers s JOIN supplier_pva sp ON sp.supplier_id = s.id WHERE sp.product_variation_attribute_id = pva.id AND sp.statut = 1 AND s.account_id = ? ORDER BY sp.id DESC LIMIT 1),
                    (SELECT s.title FROM supplier_order_pva sop JOIN supplier_orders so ON so.id = sop.supplier_order_id JOIN suppliers s ON s.id = so.supplier_id WHERE sop.product_variation_attribute_id = pva.id AND sop.deleted_at IS NULL AND so.deleted_at IS NULL AND s.account_id = ? ORDER BY sop.id DESC LIMIT 1),
                    (SELECT s.title FROM supplier_order_pva sop JOIN supplier_receipts sr ON sr.id = sop.supplier_receipt_id JOIN suppliers s ON s.id = sr.supplier_id WHERE sop.product_variation_attribute_id = pva.id AND sop.deleted_at IS NULL AND sr.deleted_at IS NULL AND s.account_id = ? ORDER BY sop.id DESC LIMIT 1)
                ) as supplier_name,
                COALESCE(
                    (SELECT sp.price FROM supplier_pva sp JOIN suppliers s ON s.id = sp.supplier_id WHERE sp.product_variation_attribute_id = pva.id AND sp.statut = 1 AND s.account_id = ? ORDER BY sp.id DESC LIMIT 1),
                    (SELECT sop.price FROM supplier_order_pva sop JOIN supplier_orders so ON so.id = sop.supplier_order_id JOIN suppliers s ON s.id = so.supplier_id WHERE sop.product_variation_attribute_id = pva.id AND sop.deleted_at IS NULL AND so.deleted_at IS NULL AND s.account_id = ? AND sop.price > 0 ORDER BY sop.id DESC LIMIT 1)
                ) as supplier_price,
                (SELECT COALESCE(SUM(op.quantity), 0) FROM order_pva op JOIN orders o ON o.id = op.order_id
                    WHERE op.product_variation_attribute_id = pva.id AND op.deleted_at IS NULL AND o.deleted_at IS NULL
                    AND o.account_id = ? AND o.order_status_id IN ($statuses)
                    AND o.created_at >= DATE_SUB(NOW(), INTERVAL $window DAY)) as sold,
                (SELECT COALESCE(SUM(op.quantity), 0) FROM order_pva op JOIN orders o ON o.id = op.order_id
                    WHERE op.product_variation_attribute_id = pva.id AND op.deleted_at IS NULL AND o.deleted_at IS NULL
                    AND o.account_id = ? AND o.order_status_id IN (5, 6, 9)) as in_delivery,
                (SELECT COALESCE(SUM(GREATEST(0, sop.quantity - COALESCE(sop.received_quantity, 0) - COALESCE(sop.cancelled_quantity, 0))), 0)
                    FROM supplier_order_pva sop
                    JOIN supplier_orders so ON so.id = sop.supplier_order_id
                    JOIN suppliers s ON s.id = so.supplier_id
                    WHERE sop.product_variation_attribute_id = pva.id
                    AND sop.supplier_receipt_id IS NULL
                    AND sop.deleted_at IS NULL
                    AND so.deleted_at IS NULL
                    AND s.account_id = ?
                    AND so.statut IN (1, 2)) as pending_supplier_order,
                (SELECT GROUP_CONCAT(DISTINCT so.code ORDER BY so.id ASC SEPARATOR ', ')
                    FROM supplier_order_pva sop
                    JOIN supplier_orders so ON so.id = sop.supplier_order_id
                    JOIN suppliers s ON s.id = so.supplier_id
                    WHERE sop.product_variation_attribute_id = pva.id
                    AND sop.supplier_receipt_id IS NULL
                    AND sop.deleted_at IS NULL
                    AND so.deleted_at IS NULL
                    AND s.account_id = ?
                    AND so.statut IN (1, 2)
                    AND (sop.quantity - COALESCE(sop.received_quantity, 0) - COALESCE(sop.cancelled_quantity, 0)) > 0) as open_order_codes", [
                $defaultThreshold,
                $accountId, $accountId, $accountId,
                $accountId, $accountId, $accountId,
                $accountId, $accountId,
                $accountId,
                $accountId,
                $accountId,
                $accountId,
            ])
            ->havingRaw('stock <= threshold')
            ->get();

        $items = $rows->map(function ($r) use ($window, $coverDays) {
            $velocity = round($r->sold / $window, 2);
            $stock = (float) $r->stock;
            $threshold = (int) $r->threshold;
            $pendingOrder = (float) ($r->pending_supplier_order ?? 0);

            $rawSuggested = $velocity > 0
                ? (int) max(0, ceil($velocity * $coverDays - max($stock, 0)))
                : (int) max(0, $threshold - max($stock, 0));

            $suggestedAfterPending = (int) max(0, $rawSuggested - $pendingOrder);

            $openCodes = $r->open_order_codes
                ? array_values(array_filter(explode(', ', (string) $r->open_order_codes)))
                : [];

            return [
                'pva_id'                 => $r->pva_id,
                'pva_code'               => $r->pva_code,
                'product_id'             => $r->product_id,
                'product_title'          => $r->product_title,
                'variation'              => $r->variation,
                'stock'                  => $stock,
                'in_delivery'            => (float) $r->in_delivery,
                'pending_supplier_order' => $pendingOrder,
                'open_order_codes'       => $openCodes,
                'threshold'              => $threshold,
                'daily_velocity'         => $velocity,
                'days_of_cover'          => $velocity > 0 && $stock > 0 ? round($stock / $velocity, 1) : null,
                'raw_suggested_reorder'  => $rawSuggested,
                'suggested_reorder'      => $suggestedAfterPending,
                'supplier_id'            => $r->supplier_id ? (int) $r->supplier_id : null,
                'supplier_name'          => $r->supplier_name,
                'supplier_price'         => $r->supplier_price !== null ? (float) $r->supplier_price : null,
                'level'                  => $stock < 0 ? 'negative' : ($stock == 0 ? 'out' : 'low'),
            ];
        });

        $order = ['negative' => 0, 'out' => 1, 'low' => 2];
        $sorted = $items->sort(fn ($a, $b) => [$order[$a['level']], -$a['daily_velocity']] <=> [$order[$b['level']], -$b['daily_velocity']])->values();

        return [
            'summary' => [
                'negative'            => $items->where('level', 'negative')->count(),
                'out'                 => $items->where('level', 'out')->count(),
                'low'                 => $items->where('level', 'low')->count(),
                'pending_order_units' => (float) $items->sum('pending_supplier_order'),
            ],
            'items' => $sorted->take($limit)->all(),
            'window_days' => $window,
            'cover_days' => $coverDays,
        ];
    }

    // endregion

    // region reconciliation

    /**
     * Negative stock rows with the movement ledger that produced them.
     * "entries" and "exits" come from the applied (statut = 1) movement lines; whatever the
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
                (SELECT COALESCE(SUM(mp.quantity), 0) FROM mouvement_pva mp
                    JOIN mouvements m ON m.id = mp.mouvement_id
                    WHERE mp.product_variation_attribute_id = pva.id AND m.to_warehouse = w.warehouse_id
                    AND mp.statut = 1 AND m.statut = 1
                    AND mp.deleted_at IS NULL AND m.deleted_at IS NULL) as entries,
                (SELECT COALESCE(SUM(mp.quantity), 0) FROM mouvement_pva mp
                    JOIN mouvements m ON m.id = mp.mouvement_id
                    WHERE mp.product_variation_attribute_id = pva.id AND m.from_warehouse = w.warehouse_id
                    AND mp.statut = 1 AND m.statut = 1
                    AND mp.deleted_at IS NULL AND m.deleted_at IS NULL) as exits,
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
     * Sets a warehouse stock line to a counted quantity and records both an audit row
     * in stock_adjustments AND a corresponding Mouvement in the movement ledger so
     * that WarehousePva and Mouvement stay 100% synchronized.
     *
     * @return array{old: float, new: float, mouvement_id: int|null}
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

            $oldQuantity = (float) $line->quantity;
            $diff = $newQuantity - $oldQuantity;

            DB::table('warehouse_pva')->where('id', $line->id)->update(['quantity' => $newQuantity, 'updated_at' => now()]);

            DB::table('stock_adjustments')->insert([
                'account_id'                      => $accountId,
                'warehouse_id'                    => $line->warehouse_id,
                'product_variation_attribute_id'  => $line->product_variation_attribute_id,
                'account_user_id'                 => $accountUserId,
                'old_quantity'                    => $oldQuantity,
                'new_quantity'                    => $newQuantity,
                'reason'                          => $reason,
                'created_at'                      => now(),
                'updated_at'                      => now(),
            ]);

            $mouvementId = null;
            if (abs($diff) > 0) {
                $mouvement = Mouvement::create([
                    'code'              => DefaultCodeController::getAccountCode('Mouvement', $accountId),
                    'mouvement_type_id' => 1,
                    'from_warehouse'    => $diff < 0 ? $line->warehouse_id : null,
                    'to_warehouse'      => $diff > 0 ? $line->warehouse_id : null,
                    'description'       => "Ajustement d'inventaire: " . $reason . " ({$oldQuantity} -> {$newQuantity})",
                    'statut'            => 1,
                    'account_user_id'   => $accountUserId,
                ]);

                MouvementPva::create([
                    'mouvement_id'                   => $mouvement->id,
                    'product_variation_attribute_id' => $line->product_variation_attribute_id,
                    'quantity'                       => abs($diff),
                    'price'                          => 0,
                    'statut'                         => 1,
                    'account_user_id'                => $accountUserId,
                ]);

                $mouvementId = $mouvement->id;
            }

            return ['old' => $oldQuantity, 'new' => $newQuantity, 'mouvement_id' => $mouvementId];
        });
    }

    // endregion
}

