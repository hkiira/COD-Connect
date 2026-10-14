<?php

namespace App\Http\Controllers;

use App\Models\Pickup;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Shipment;
use App\Models\SupplierReceipt;
use App\Support\ProductOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductAnalyticsController extends Controller
{
    private const VARIATION_LABEL_SQL = "(SELECT GROUP_CONCAT(CONCAT(ta.title, ': ', a.title) ORDER BY ta.title SEPARATOR ' · ')
        FROM variation_attributes va
        JOIN attributes a ON a.id = va.attribute_id
        JOIN type_attributes ta ON ta.id = a.types_attribute_id
        WHERE va.variation_attribute_id = pva.variation_attribute_id)";

    private function accountId(): int
    {
        return (int) getAccountUser()->account_id;
    }

    /**
     * Get unified stock movement history for a specific product and all its variations.
     */
    public function movements(Request $request, $id): JsonResponse
    {
        if (! ProductOwnership::owns($id)) {
            return response()->json(['statut' => 0, 'message' => 'Produit non trouvé pour ce compte'], 404);
        }

        $accountId = $this->accountId();
        $label = self::VARIATION_LABEL_SQL;

        $pvaQuery = ProductVariationAttribute::where('product_id', $id)->whereNull('deleted_at');
        if ($request->filled('pva_id')) {
            $pvaQuery->where('id', (int) $request->input('pva_id'));
        }
        $pvaIds = $pvaQuery->pluck('id')->toArray();

        if (empty($pvaIds)) {
            return response()->json([
                'statut' => 1,
                'data' => [
                    'summary' => ['total_in' => 0, 'total_out' => 0, 'net_quantity' => 0, 'total_records' => 0],
                    'items' => [],
                ],
            ]);
        }

        // 1. Fetch Movements from mouvement_pva
        $mvtQuery = DB::table('mouvement_pva as mp')
            ->join('mouvements as m', 'm.id', '=', 'mp.mouvement_id')
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'mp.product_variation_attribute_id')
            ->leftJoin('mouvement_types as mt', 'mt.id', '=', 'm.mouvement_type_id')
            ->leftJoin('warehouses as fw', 'fw.id', '=', 'm.from_warehouse')
            ->leftJoin('warehouses as tw', 'tw.id', '=', 'm.to_warehouse')
            ->leftJoin('account_user as au', 'au.id', '=', 'mp.account_user_id')
            ->leftJoin('users as u', 'u.id', '=', 'au.user_id')
            ->whereIn('mp.product_variation_attribute_id', $pvaIds)
            ->whereNull('mp.deleted_at')
            ->whereNull('m.deleted_at');

        if ($request->filled('date_from')) {
            $mvtQuery->where('m.created_at', '>=', $request->input('date_from') . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $mvtQuery->where('m.created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }
        if ($request->filled('warehouse_id')) {
            $whId = (int) $request->input('warehouse_id');
            $mvtQuery->where(function ($q) use ($whId) {
                $q->where('m.from_warehouse', $whId)->orWhere('m.to_warehouse', $whId);
            });
        }

        $rawMouvements = $mvtQuery->selectRaw("
            mp.id as mp_id,
            m.id as mouvement_id,
            m.code as mouvement_code,
            m.created_at,
            mt.id as type_id,
            mt.title as type_title,
            m.from_warehouse as from_warehouse_id,
            fw.title as from_warehouse_title,
            m.to_warehouse as to_warehouse_id,
            tw.title as to_warehouse_title,
            pva.id as pva_id,
            pva.code as pva_code,
            $label as variation,
            mp.quantity,
            u.name as user_name
        ")
            ->orderByDesc('m.created_at')
            ->limit(500)
            ->get();

        $movementIds = $rawMouvements->pluck('mouvement_id')->unique()->filter()->values()->toArray();

        // Documents linking
        $pickups = !empty($movementIds)
            ? Pickup::with('carrier:id,title')->whereIn('mouvement_id', $movementIds)->get()->keyBy('mouvement_id')
            : collect();

        $receipts = !empty($movementIds)
            ? SupplierReceipt::with('supplier:id,title')->whereIn('mouvement_id', $movementIds)->get()->keyBy('mouvement_id')
            : collect();

        $shipments = !empty($movementIds)
            ? Shipment::with('carrier:id,title')->whereIn('mouvement_id', $movementIds)->get()->keyBy('mouvement_id')
            : collect();

        $mouvementItems = $rawMouvements->map(function ($row) use ($pickups, $receipts, $shipments) {
            $pickup = $pickups->get($row->mouvement_id);
            $receipt = $receipts->get($row->mouvement_id);
            $shipment = $shipments->get($row->mouvement_id);

            $type = 'mouvement';
            $typeLabel = $row->type_title ?: 'Mouvement de stock';
            $direction = 'neutral';
            $refCode = $row->mouvement_code;
            $entityName = null;
            $qty = (float) $row->quantity;

            if ($receipt) {
                $type = 'receipt';
                $typeLabel = 'Réception Fournisseur';
                $direction = 'in';
                $refCode = $receipt->code ?: $row->mouvement_code;
                $entityName = $receipt->supplier?->title;
            } elseif ($pickup) {
                $type = 'pickup';
                $typeLabel = 'Bon de Ramassage';
                $direction = 'out';
                $refCode = $pickup->code ?: $row->mouvement_code;
                $entityName = $pickup->carrier?->title;
                $qty = -abs($qty);
            } elseif ($shipment) {
                $isReturn = str_contains(strtolower($shipment->code ?? ''), 'ret') || $row->to_warehouse_id !== null;
                $type = $isReturn ? 'return' : 'shipment';
                $typeLabel = $isReturn ? 'Retour Transporteur' : 'Expédition Colis';
                $direction = $isReturn ? 'in' : 'out';
                $refCode = $shipment->code ?: $row->mouvement_code;
                $entityName = $shipment->carrier?->title;
                if (! $isReturn) {
                    $qty = -abs($qty);
                }
            } else {
                if ($row->to_warehouse_id && ! $row->from_warehouse_id) {
                    $direction = 'in';
                    $typeLabel = 'Entrée de stock';
                } elseif ($row->from_warehouse_id && ! $row->to_warehouse_id) {
                    $direction = 'out';
                    $typeLabel = 'Sortie de stock';
                    $qty = -abs($qty);
                } elseif ($row->from_warehouse_id && $row->to_warehouse_id) {
                    $type = 'transfer';
                    $typeLabel = 'Transfert Inter-entrepôts';
                    $direction = 'transfer';
                }
            }

            return [
                'id' => "mvt-{$row->mp_id}",
                'date' => $row->created_at,
                'type' => $type,
                'type_label' => $typeLabel,
                'direction' => $direction,
                'quantity' => $qty,
                'variation' => $row->variation ?: ($row->pva_code ?: 'Standard'),
                'pva_id' => $row->pva_id,
                'from_warehouse' => $row->from_warehouse_title ?: '—',
                'to_warehouse' => $row->to_warehouse_title ?: '—',
                'reference' => $refCode,
                'entity' => $entityName ?: '—',
                'user' => $row->user_name ?: 'Système',
                'notes' => null,
            ];
        });

        // 2. Fetch Adjustments from stock_adjustments
        $adjQuery = DB::table('stock_adjustments as sa')
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'sa.product_variation_attribute_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sa.warehouse_id')
            ->leftJoin('account_user as au', 'au.id', '=', 'sa.account_user_id')
            ->leftJoin('users as u', 'u.id', '=', 'au.user_id')
            ->whereIn('sa.product_variation_attribute_id', $pvaIds)
            ->where('sa.account_id', $accountId);

        if ($request->filled('date_from')) {
            $adjQuery->where('sa.created_at', '>=', $request->input('date_from') . ' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $adjQuery->where('sa.created_at', '<=', $request->input('date_to') . ' 23:59:59');
        }
        if ($request->filled('warehouse_id')) {
            $adjQuery->where('sa.warehouse_id', (int) $request->input('warehouse_id'));
        }

        $rawAdjustments = $adjQuery->selectRaw("
            sa.id,
            sa.created_at,
            sa.warehouse_id,
            w.title as warehouse_title,
            sa.product_variation_attribute_id as pva_id,
            pva.code as pva_code,
            $label as variation,
            sa.old_quantity,
            sa.new_quantity,
            (sa.new_quantity - sa.old_quantity) as quantity_diff,
            sa.reason,
            u.name as user_name
        ")
            ->orderByDesc('sa.created_at')
            ->limit(200)
            ->get();

        $adjustmentItems = $rawAdjustments->map(function ($row) {
            $diff = (float) $row->quantity_diff;
            return [
                'id' => "adj-{$row->id}",
                'date' => $row->created_at,
                'type' => 'adjustment',
                'type_label' => "Ajustement d'inventaire",
                'direction' => $diff >= 0 ? 'in' : 'out',
                'quantity' => $diff,
                'variation' => $row->variation ?: ($row->pva_code ?: 'Standard'),
                'pva_id' => $row->pva_id,
                'from_warehouse' => $diff < 0 ? ($row->warehouse_title ?: 'Entrepôt') : '—',
                'to_warehouse' => $diff >= 0 ? ($row->warehouse_title ?: 'Entrepôt') : '—',
                'reference' => "AJUST-{$row->id}",
                'entity' => "Régul : {$row->old_quantity} ➔ {$row->new_quantity}",
                'user' => $row->user_name ?: 'Système',
                'notes' => $row->reason,
            ];
        });

        // Merge & Sort Chronologically DESC
        $all = $mouvementItems->concat($adjustmentItems)->sortByDesc('date')->values();

        // Optional type filter
        if ($request->filled('type') && $request->input('type') !== 'all') {
            $filterType = $request->input('type');
            $all = $all->filter(fn ($item) => $item['type'] === $filterType)->values();
        }

        $totalIn = $all->where('quantity', '>', 0)->sum('quantity');
        $totalOut = abs($all->where('quantity', '<', 0)->sum('quantity'));

        return response()->json([
            'statut' => 1,
            'data' => [
                'summary' => [
                    'total_in' => round($totalIn, 2),
                    'total_out' => round($totalOut, 2),
                    'net_quantity' => round($totalIn - $totalOut, 2),
                    'total_records' => $all->count(),
                ],
                'items' => $all->take(250)->all(),
            ],
        ]);
    }

    /**
     * Get sales & operational performance metrics for a specific product.
     */
    public function performance(Request $request, $id): JsonResponse
    {
        if (! ProductOwnership::owns($id)) {
            return response()->json(['statut' => 0, 'message' => 'Produit non trouvé pour ce compte'], 404);
        }

        $accountId = $this->accountId();
        $label = self::VARIATION_LABEL_SQL;

        $product = Product::find($id);
        $pvaIds = ProductVariationAttribute::where('product_id', $id)->whereNull('deleted_at')->pluck('id')->toArray();

        if (empty($pvaIds)) {
            return response()->json([
                'statut' => 1,
                'data' => [
                    'kpis' => [
                        'total_orders' => 0,
                        'total_units_sold' => 0,
                        'delivered_orders' => 0,
                        'delivered_units' => 0,
                        'returned_orders' => 0,
                        'returned_units' => 0,
                        'cancelled_orders' => 0,
                        'out_of_stock_orders' => 0,
                        'in_transit_orders' => 0,
                        'total_revenue' => 0,
                        'delivery_rate' => 0,
                        'return_rate' => 0,
                        'current_real_stock' => 0,
                        'daily_velocity' => 0,
                        'days_of_cover' => null,
                    ],
                    'timeline' => [],
                    'variations' => [],
                ],
            ]);
        }

        $isCustomRange = $request->filled('date_from') && $request->filled('date_to');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        // Period filter (default 30 days if not custom range)
        $period = $request->input('period', '30');
        $days = is_numeric($period) && (int) $period > 0 ? (int) $period : 30;
        $startDate = $period === 'all' ? null : now()->subDays($days)->startOfDay();

        // 1. Base Orders Query
        $ordersQuery = DB::table('order_pva as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->whereIn('op.product_variation_attribute_id', $pvaIds)
            ->where('o.account_id', $accountId)
            ->whereNull('op.deleted_at')
            ->whereNull('o.deleted_at');

        if ($isCustomRange) {
            $ordersQuery->whereBetween('o.created_at', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
        } elseif ($startDate) {
            $ordersQuery->where('o.created_at', '>=', $startDate);
        }

        $kpisRaw = (clone $ordersQuery)->selectRaw("
            COUNT(DISTINCT o.id) as total_orders,
            COALESCE(SUM(op.quantity), 0) as total_units_sold,
            COUNT(DISTINCT CASE WHEN o.order_status_id IN (7, 10) THEN o.id END) as delivered_orders,
            COALESCE(SUM(CASE WHEN o.order_status_id IN (7, 10) THEN op.quantity ELSE 0 END), 0) as delivered_units,
            COUNT(DISTINCT CASE WHEN o.order_status_id = 11 THEN o.id END) as returned_orders,
            COALESCE(SUM(CASE WHEN o.order_status_id = 11 THEN op.quantity ELSE 0 END), 0) as returned_units,
            COUNT(DISTINCT CASE WHEN o.order_status_id IN (2, 8) THEN o.id END) as cancelled_orders,
            COUNT(DISTINCT CASE WHEN o.order_status_id = 3 THEN o.id END) as out_of_stock_orders,
            COUNT(DISTINCT CASE WHEN o.order_status_id IN (4, 5, 6, 9) THEN o.id END) as in_transit_orders,
            COALESCE(SUM(CASE WHEN o.order_status_id IN (7, 10) THEN op.price * op.quantity ELSE 0 END), 0) as total_revenue
        ")->first();

        // Current real stock across all warehouses for this product
        $currentRealStock = (float) DB::table('warehouse_pva')
            ->whereIn('product_variation_attribute_id', $pvaIds)
            ->sum('quantity');

        $totalOrders = (int) ($kpisRaw->total_orders ?? 0);
        $deliveredOrders = (int) ($kpisRaw->delivered_orders ?? 0);
        $returnedOrders = (int) ($kpisRaw->returned_orders ?? 0);
        $cancelledOrders = (int) ($kpisRaw->cancelled_orders ?? 0);
        $deliveredUnits = (float) ($kpisRaw->delivered_units ?? 0);
        $totalUnitsSold = (float) ($kpisRaw->total_units_sold ?? 0);

        // Effective delivery rate: delivered / (total - cancelled)
        $effectiveOrders = max(1, $totalOrders - $cancelledOrders);
        $deliveryRate = $totalOrders > 0 ? round(($deliveredOrders / $effectiveOrders) * 100, 1) : 0;

        // Return rate: returned / (delivered + returned)
        $shippedFinished = $deliveredOrders + $returnedOrders;
        $returnRate = $shippedFinished > 0 ? round(($returnedOrders / $shippedFinished) * 100, 1) : 0;

        if ($isCustomRange) {
            $calcDays = max(1, \Carbon\Carbon::parse($dateFrom)->diffInDays(\Carbon\Carbon::parse($dateTo)) + 1);
        } else {
            $calcDays = $startDate ? $days : max(1, now()->diffInDays($product->created_at ?: now()));
        }
        $dailyVelocity = round($totalUnitsSold / max(1, $calcDays), 2);
        $daysOfCover = $dailyVelocity > 0 && $currentRealStock > 0 ? round($currentRealStock / $dailyVelocity, 1) : null;

        // 2. Timeline for Chart (grouped by date)
        $timelineRaw = (clone $ordersQuery)->selectRaw("
            DATE(o.created_at) as date,
            COUNT(DISTINCT o.id) as orders,
            COUNT(DISTINCT CASE WHEN o.order_status_id IN (7, 10) THEN o.id END) as delivered,
            COUNT(DISTINCT CASE WHEN o.order_status_id = 11 THEN o.id END) as returned,
            COALESCE(SUM(CASE WHEN o.order_status_id IN (7, 10) THEN op.price * op.quantity ELSE 0 END), 0) as revenue
        ")
            ->groupBy(DB::raw('DATE(o.created_at)'))
            ->orderBy(DB::raw('DATE(o.created_at)'), 'ASC')
            ->get();

        $timeline = $timelineRaw->map(fn ($r) => [
            'date' => $r->date,
            'orders' => (int) $r->orders,
            'delivered' => (int) $r->delivered,
            'returned' => (int) $r->returned,
            'revenue' => round((float) $r->revenue, 2),
        ])->all();

        // 3. Breakdown by Variation
        $variationStatsRaw = DB::table('product_variation_attribute as pva')
            ->where('pva.product_id', $id)
            ->whereNull('pva.deleted_at')
            ->leftJoin('warehouse_pva as wp', 'wp.product_variation_attribute_id', '=', 'pva.id')
            ->groupBy('pva.id', 'pva.code', 'pva.variation_attribute_id')
            ->selectRaw("
                pva.id as pva_id,
                pva.code as pva_code,
                $label as variation,
                COALESCE(SUM(wp.quantity), 0) as real_stock
            ")
            ->get();

        $pvaSalesQuery = (clone $ordersQuery)->selectRaw("
            op.product_variation_attribute_id as pva_id,
            COUNT(DISTINCT o.id) as orders_count,
            COALESCE(SUM(op.quantity), 0) as units_ordered,
            COALESCE(SUM(CASE WHEN o.order_status_id IN (7, 10) THEN op.quantity ELSE 0 END), 0) as units_delivered,
            COALESCE(SUM(CASE WHEN o.order_status_id = 11 THEN op.quantity ELSE 0 END), 0) as units_returned,
            COALESCE(SUM(CASE WHEN o.order_status_id IN (7, 10) THEN op.price * op.quantity ELSE 0 END), 0) as revenue
        ")->groupBy('op.product_variation_attribute_id')->get()->keyBy('pva_id');

        $variations = $variationStatsRaw->map(function ($row) use ($pvaSalesQuery) {
            $sales = $pvaSalesQuery->get($row->pva_id);
            $uOrdered = (float) ($sales->units_ordered ?? 0);
            $uDelivered = (float) ($sales->units_delivered ?? 0);
            $uReturned = (float) ($sales->units_returned ?? 0);
            $revenue = (float) ($sales->revenue ?? 0);

            $delRate = $uOrdered > 0 ? round(($uDelivered / $uOrdered) * 100, 1) : 0;

            return [
                'pva_id' => $row->pva_id,
                'pva_code' => $row->pva_code,
                'variation' => $row->variation ?: ($row->pva_code ?: 'Standard'),
                'real_stock' => (float) $row->real_stock,
                'orders_count' => (int) ($sales->orders_count ?? 0),
                'units_ordered' => $uOrdered,
                'units_delivered' => $uDelivered,
                'units_returned' => $uReturned,
                'revenue' => round($revenue, 2),
                'delivery_rate' => $delRate,
            ];
        })->all();

        $statusDistribution = [
            'delivered'    => $deliveredOrders,
            'in_transit'   => (int) ($kpisRaw->in_transit_orders ?? 0),
            'returned'     => $returnedOrders,
            'out_of_stock' => (int) ($kpisRaw->out_of_stock_orders ?? 0),
            'cancelled'    => $cancelledOrders,
        ];

        return response()->json([
            'statut' => 1,
            'data' => [
                'period' => $period,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'kpis' => [
                    'total_orders' => $totalOrders,
                    'total_units_sold' => (int) $totalUnitsSold,
                    'delivered_orders' => $deliveredOrders,
                    'delivered_units' => (int) $deliveredUnits,
                    'returned_orders' => $returnedOrders,
                    'returned_units' => (int) ($kpisRaw->returned_units ?? 0),
                    'cancelled_orders' => $cancelledOrders,
                    'out_of_stock_orders' => (int) ($kpisRaw->out_of_stock_orders ?? 0),
                    'in_transit_orders' => (int) ($kpisRaw->in_transit_orders ?? 0),
                    'total_revenue' => round((float) ($kpisRaw->total_revenue ?? 0), 2),
                    'avg_order_value' => $deliveredOrders > 0 ? round((float) ($kpisRaw->total_revenue ?? 0) / $deliveredOrders, 2) : 0,
                    'delivery_rate' => $deliveryRate,
                    'return_rate' => $returnRate,
                    'current_real_stock' => $currentRealStock,
                    'daily_velocity' => $dailyVelocity,
                    'days_of_cover' => $daysOfCover,
                ],
                'status_distribution' => $statusDistribution,
                'timeline' => $timeline,
                'variations' => $variations,
            ],
        ]);
    }
}
