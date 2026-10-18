<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\Pickup;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Shipment;
use App\Models\SupplierReceipt;
use App\Models\Warehouse;
use App\Models\WarehousePva;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MouvementController extends Controller
{
    /**
     * Expand a list of warehouse IDs to include all child partitions (type=2)
     * and grandchild rayons (type=3) belonging to the account.
     */
    private function expandWarehouseIds(array $warehouseIds, int $accountId): array
    {
        $warehouseIds = array_values(array_filter(array_map('intval', $warehouseIds)));
        if (empty($warehouseIds)) {
            return [];
        }

        $allAccountWarehouses = Warehouse::where('account_id', $accountId)
            ->get(['id', 'warehouse_id']);

        $expanded = array_fill_keys($warehouseIds, true);
        $added = true;

        while ($added) {
            $added = false;
            foreach ($allAccountWarehouses as $wh) {
                $id = (int) $wh->id;
                $parentId = $wh->warehouse_id ? (int) $wh->warehouse_id : null;
                if ($parentId && isset($expanded[$parentId]) && !isset($expanded[$id])) {
                    $expanded[$id] = true;
                    $added = true;
                }
            }
        }

        return array_keys($expanded);
    }

    /**
     * Build a map of warehouse_id => readable display title (including parent warehouse name if rayon/partition).
     */
    private function buildWarehouseDisplayMap(int $accountId): array
    {
        $warehouses = Warehouse::where('account_id', $accountId)
            ->get(['id', 'title', 'warehouse_id', 'warehouse_type_id'])
            ->keyBy('id');

        $map = [];
        foreach ($warehouses as $id => $wh) {
            $root = $wh;
            $guard = 0;
            while ($root->warehouse_id && isset($warehouses[$root->warehouse_id]) && $guard < 5) {
                $root = $warehouses[$root->warehouse_id];
                $guard++;
            }
            $map[(int) $id] = [
                'id' => (int) $wh->id,
                'title' => $wh->title,
                'root_id' => (int) $root->id,
                'root_title' => $root->title,
                'display_title' => ((int) $root->id !== (int) $wh->id)
                    ? $root->title . ' (' . $wh->title . ')'
                    : $wh->title,
            ];
        }

        return $map;
    }

    /**
     * Build the account's product & variation catalog for the toolbar selectors.
     */
    private function buildFilterCatalog(array $accountUserIds, array $accountWarehouseIds): array
    {
        $products = Product::with([
            'images',
            'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
        ])
            ->whereIn('account_user_id', $accountUserIds)
            ->orderBy('title', 'asc')
            ->get();

        $stockByPva = WarehousePva::whereIn('warehouse_id', $accountWarehouseIds)
            ->select('product_variation_attribute_id', DB::raw('SUM(quantity) as total_qty'))
            ->groupBy('product_variation_attribute_id')
            ->pluck('total_qty', 'product_variation_attribute_id');

        $productOptions = [];
        $variationOptions = [];

        foreach ($products as $product) {
            $productStock = 0.0;
            $productVariationsList = [];

            foreach ($product->productVariationAttributes as $pva) {
                $pvaId = (int) $pva->id;
                $qty = (float) ($stockByPva[$pvaId] ?? 0);
                $productStock += $qty;

                $parts = [];
                $attrs = [];
                if ($pva->variationAttribute && $pva->variationAttribute->childVariationAttributes) {
                    foreach ($pva->variationAttribute->childVariationAttributes as $childVa) {
                        if ($childVa->attribute && $childVa->attribute->title) {
                            $typeTitle = $childVa->attribute->typeAttribute?->title ?? '';
                            $valTitle = $childVa->attribute->title;
                            $parts[] = $valTitle;
                            $attrs[] = [
                                'attribute_id' => (int) $childVa->attribute->id,
                                'type' => $typeTitle,
                                'value' => $valTitle,
                            ];
                        }
                    }
                }

                $variationLabel = !empty($parts) ? implode(' · ', $parts) : ('Standard #' . $pvaId);
                $fullTitle = $product->title . ' — ' . $variationLabel;

                $varItem = [
                    'id' => $pvaId,
                    'pva_id' => $pvaId,
                    'product_id' => (int) $product->id,
                    'product_title' => $product->title,
                    'product_reference' => $product->reference,
                    'variation_label' => $variationLabel,
                    'title' => $fullTitle,
                    'stock_qty' => $qty,
                    'attributes' => $attrs,
                ];

                $variationOptions[] = $varItem;
                $productVariationsList[] = $varItem;
            }

            $productOptions[] = [
                'id' => (int) $product->id,
                'title' => $product->title,
                'reference' => $product->reference,
                'stock_qty' => $productStock,
                'variations_count' => count($productVariationsList),
                'variations' => $productVariationsList,
            ];
        }

        return [
            'products' => $productOptions,
            'productPvas' => $variationOptions,
        ];
    }

    public function index(Request $request)
    {
        $params = collect($request->query())->toArray();
        $accountUser = getAccountUser();
        $accountId = (int) $accountUser->account_id;

        $accountUserIds = AccountUser::where('account_id', $accountId)->pluck('id')->toArray();
        $accountWarehouseIds = Warehouse::where('account_id', $accountId)->pluck('id')->toArray();
        $warehouseDisplayMap = $this->buildWarehouseDisplayMap($accountId);

        // Base query scoped to account users OR account warehouses
        $query = Mouvement::query()->where(function ($q) use ($accountUserIds, $accountWarehouseIds) {
            $q->whereIn('account_user_id', $accountUserIds);
            if (!empty($accountWarehouseIds)) {
                $q->orWhereIn('from_warehouse', $accountWarehouseIds)
                    ->orWhereIn('to_warehouse', $accountWarehouseIds);
            }
        });

        // 1. Filter by Suppliers
        if (!empty($params['suppliers']) && is_array($params['suppliers'])) {
            $supplierIds = array_values(array_filter(array_map('intval', $params['suppliers'])));
            if (!empty($supplierIds)) {
                $query->whereIn(
                    'id',
                    SupplierReceipt::whereIn('supplier_id', $supplierIds)
                        ->whereNotNull('mouvement_id')
                        ->select('mouvement_id')
                );
            }
        }

        // 2. Filter by Carriers
        if (!empty($params['carriers']) && is_array($params['carriers'])) {
            $carrierIds = array_values(array_filter(array_map('intval', $params['carriers'])));
            if (!empty($carrierIds)) {
                $pickupMvtIds = Pickup::whereIn('carrier_id', $carrierIds)
                    ->whereNotNull('mouvement_id')
                    ->pluck('mouvement_id')
                    ->toArray();
                $shipmentMvtIds = Shipment::whereIn('carrier_id', $carrierIds)
                    ->whereNotNull('mouvement_id')
                    ->pluck('mouvement_id')
                    ->toArray();
                $carrierMvtIds = array_values(array_unique(array_merge($pickupMvtIds, $shipmentMvtIds)));
                $query->whereIn('id', !empty($carrierMvtIds) ? $carrierMvtIds : [0]);
            }
        }

        // 3. Filter by Warehouses (expanding root warehouses to include their partitions and rayons)
        $selectedWarehouseIds = [];
        if (!empty($params['warehouses'])) {
            $selectedWarehouseIds = array_merge($selectedWarehouseIds, (array) $params['warehouses']);
        }
        if (!empty($params['warehouse_ids'])) {
            $rawWh = is_array($params['warehouse_ids'])
                ? $params['warehouse_ids']
                : explode(',', (string) $params['warehouse_ids']);
            $selectedWarehouseIds = array_merge($selectedWarehouseIds, $rawWh);
        }
        $expandedWarehouseIds = $this->expandWarehouseIds($selectedWarehouseIds, $accountId);
        if (!empty($expandedWarehouseIds)) {
            $query->where(function ($q) use ($expandedWarehouseIds) {
                $q->whereIn('from_warehouse', $expandedWarehouseIds)
                    ->orWhereIn('to_warehouse', $expandedWarehouseIds);
            });
        }

        if (!empty($params['from_warehouses']) && is_array($params['from_warehouses'])) {
            $fromWhIds = $this->expandWarehouseIds($params['from_warehouses'], $accountId);
            if (!empty($fromWhIds)) {
                $query->whereIn('from_warehouse', $fromWhIds);
            }
        }

        if (!empty($params['to_warehouses']) && is_array($params['to_warehouses'])) {
            $toWhIds = $this->expandWarehouseIds($params['to_warehouses'], $accountId);
            if (!empty($toWhIds)) {
                $query->whereIn('to_warehouse', $toWhIds);
            }
        }

        // 4. Filter by Products & Variations (supports both pva_id and attribute_id)
        $selectedProductIds = [];
        if (!empty($params['products']) && is_array($params['products'])) {
            $selectedProductIds = array_values(array_filter(array_map('intval', $params['products'])));
        }

        $rawVariationIds = [];
        if (!empty($params['productPvas']) && is_array($params['productPvas'])) {
            $rawVariationIds = array_merge($rawVariationIds, $params['productPvas']);
        }
        if (!empty($params['variations']) && is_array($params['variations'])) {
            $rawVariationIds = array_merge($rawVariationIds, $params['variations']);
        }
        $rawVariationIds = array_values(array_filter(array_map('intval', $rawVariationIds)));

        $activeFilterPvaIds = null;

        if (!empty($selectedProductIds)) {
            $activeFilterPvaIds = ProductVariationAttribute::whereIn('product_id', $selectedProductIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();
        }

        if (!empty($rawVariationIds)) {
            $accountProductIds = Product::whereIn('account_user_id', $accountUserIds)->pluck('id')->toArray();
            $directPvaIds = ProductVariationAttribute::whereIn('product_id', $accountProductIds)
                ->whereIn('id', $rawVariationIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->toArray();

            if (!empty($directPvaIds)) {
                $resolvedVariationPvaIds = $directPvaIds;
            } else {
                // Fallback if legacy attribute_id was passed
                $resolvedVariationPvaIds = ProductVariationAttribute::whereIn('product_id', $accountProductIds)
                    ->whereHas('variationAttribute.childVariationAttributes', function ($q) use ($rawVariationIds) {
                        $q->whereIn('attribute_id', $rawVariationIds);
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->toArray();
            }

            if ($activeFilterPvaIds !== null) {
                $activeFilterPvaIds = array_values(array_intersect($activeFilterPvaIds, $resolvedVariationPvaIds));
            } else {
                $activeFilterPvaIds = $resolvedVariationPvaIds;
            }
        }

        if ($activeFilterPvaIds !== null) {
            if (empty($activeFilterPvaIds)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereExists(function ($sub) use ($activeFilterPvaIds) {
                    $sub->select(DB::raw(1))
                        ->from('mouvement_pva')
                        ->whereColumn('mouvement_pva.mouvement_id', 'mouvements.id')
                        ->whereNull('mouvement_pva.deleted_at')
                        ->whereIn('mouvement_pva.product_variation_attribute_id', $activeFilterPvaIds);
                });
            }
        }

        // 5. Filter by Mouvement Types
        if (!empty($params['mouvement_types']) && is_array($params['mouvement_types'])) {
            $mvtTypeIds = array_values(array_filter(array_map('intval', $params['mouvement_types'])));
            if (!empty($mvtTypeIds)) {
                $query->whereIn('mouvement_type_id', $mvtTypeIds);
            }
        }
        if (isset($params['mouvement_type_id']) && $params['mouvement_type_id'] !== '') {
            $query->where('mouvement_type_id', (int) $params['mouvement_type_id']);
        }

        // 6. Filter by Stock Action (add / remove / transfer / none)
        if (!empty($params['stock_action']) && $params['stock_action'] !== 'all') {
            $stockAction = strtolower((string) $params['stock_action']);
            if ($stockAction === 'add') {
                $query->whereNotNull('to_warehouse')->whereNull('from_warehouse');
            } elseif ($stockAction === 'remove') {
                $query->whereNotNull('from_warehouse')->whereNull('to_warehouse');
            } elseif ($stockAction === 'transfer') {
                $query->whereNotNull('from_warehouse')->whereNotNull('to_warehouse');
            } elseif ($stockAction === 'none') {
                $query->whereNull('from_warehouse')->whereNull('to_warehouse');
            }
        }

        // 7. Filter by Source Types (pickup, supplier_receipt, shipment, return, adjustment, transfer, manual)
        $requestedSourceTypes = [];
        if (!empty($params['type']) && $params['type'] !== 'all') {
            $requestedSourceTypes[] = strtolower((string) $params['type']);
        }
        if (!empty($params['source_types']) && is_array($params['source_types'])) {
            foreach ($params['source_types'] as $st) {
                if ($st !== null && $st !== '') {
                    $requestedSourceTypes[] = strtolower((string) $st);
                }
            }
        }
        $requestedSourceTypes = array_values(array_unique($requestedSourceTypes));

        if (!empty($requestedSourceTypes)) {
            $pickupSourceIds = Pickup::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();
            $supplierReceiptSourceIds = SupplierReceipt::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();
            $shipmentSourceIds = Shipment::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();
            $adjustmentIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                ->where('description', 'LIKE', "Ajustement d'inventaire:%")
                ->pluck('id')
                ->toArray();

            $sourceIds = [];
            if (in_array('pickup', $requestedSourceTypes, true)) {
                $sourceIds = array_merge($sourceIds, $pickupSourceIds);
            }
            if (in_array('supplier_receipt', $requestedSourceTypes, true) || in_array('receipt', $requestedSourceTypes, true)) {
                $sourceIds = array_merge($sourceIds, $supplierReceiptSourceIds);
            }
            if (in_array('shipment', $requestedSourceTypes, true) || in_array('return', $requestedSourceTypes, true)) {
                $sourceIds = array_merge($sourceIds, $shipmentSourceIds);
            }
            if (in_array('adjustment', $requestedSourceTypes, true)) {
                $sourceIds = array_merge($sourceIds, $adjustmentIds);
            }
            if (in_array('transfer', $requestedSourceTypes, true)) {
                $transferIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                    ->whereNotNull('from_warehouse')
                    ->whereNotNull('to_warehouse')
                    ->pluck('id')
                    ->toArray();
                $sourceIds = array_merge($sourceIds, $transferIds);
            }
            if (in_array('manual', $requestedSourceTypes, true)) {
                $knownSourceIds = array_values(array_unique(array_merge(
                    $pickupSourceIds,
                    $supplierReceiptSourceIds,
                    $shipmentSourceIds,
                    $adjustmentIds
                )));
                $manualIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                    ->when(!empty($knownSourceIds), fn ($q) => $q->whereNotIn('id', $knownSourceIds))
                    ->pluck('id')
                    ->toArray();
                $sourceIds = array_merge($sourceIds, $manualIds);
            }

            $sourceIds = array_values(array_unique($sourceIds));
            $query->whereIn('id', !empty($sourceIds) ? $sourceIds : [0]);
        }

        // 8. Filter by Statut
        if (isset($params['statut']) && $params['statut'] !== '' && $params['statut'] !== 'all') {
            $rawStatut = strtolower((string) $params['statut']);
            if (in_array($rawStatut, ['1', 'completed', 'validated'], true)) {
                $query->where('statut', 1);
            } elseif (in_array($rawStatut, ['0', 'cancelled', 'pending'], true)) {
                $query->where('statut', 0);
            }
        }

        // 9. Filter by Date Range
        $startDate = $params['date_from'] ?? $params['start_date'] ?? null;
        $endDate = $params['date_to'] ?? $params['end_date'] ?? null;
        if (!empty($startDate)) {
            $query->whereDate('created_at', '>=', substr((string) $startDate, 0, 10));
        }
        if (!empty($endDate)) {
            $query->whereDate('created_at', '<=', substr((string) $endDate, 0, 10));
        }

        // 10. Search Query (Code, Description, Receipt, Supplier, Order Code, Pickup, Carrier, Product)
        if (!empty($params['search'])) {
            $q = trim((string) $params['search']);
            if ($q !== '') {
                $byReceiptIds = SupplierReceipt::whereNotNull('mouvement_id')
                    ->where(function ($sub) use ($q) {
                        $sub->where('code', 'LIKE', "%{$q}%")
                            ->orWhereHas('supplier', fn ($sq) => $sq->where('title', 'LIKE', "%{$q}%"))
                            ->orWhereHas('supplierOrderPvas.orderAllocations.orderLine.supplierOrder', fn ($oq) => $oq->where('code', 'LIKE', "%{$q}%"));
                    })
                    ->pluck('mouvement_id')
                    ->toArray();

                $byPickupIds = Pickup::whereNotNull('mouvement_id')
                    ->where(function ($sub) use ($q) {
                        $sub->where('code', 'LIKE', "%{$q}%")
                            ->orWhereHas('carrier', fn ($cq) => $cq->where('title', 'LIKE', "%{$q}%"))
                            ->orWhereHas('orders', fn ($oq) => $oq->where('code', 'LIKE', "%{$q}%"));
                    })
                    ->pluck('mouvement_id')
                    ->toArray();

                $byShipmentIds = Shipment::whereNotNull('mouvement_id')
                    ->where(function ($sub) use ($q) {
                        $sub->where('code', 'LIKE', "%{$q}%")
                            ->orWhereHas('carrier', fn ($cq) => $cq->where('title', 'LIKE', "%{$q}%"))
                            ->orWhereHas('orders', fn ($oq) => $oq->where('code', 'LIKE', "%{$q}%"));
                    })
                    ->pluck('mouvement_id')
                    ->toArray();

                $byProductPvaIds = ProductVariationAttribute::whereHas('product', function ($pq) use ($q) {
                    $pq->where('title', 'LIKE', "%{$q}%")
                        ->orWhere('reference', 'LIKE', "%{$q}%");
                })->pluck('id')->toArray();

                $externalMovementIds = array_values(array_unique(array_merge($byReceiptIds, $byPickupIds, $byShipmentIds)));

                $query->where(function ($sub) use ($q, $externalMovementIds, $byProductPvaIds) {
                    $sub->where('code', 'LIKE', "%{$q}%")
                        ->orWhere('description', 'LIKE', "%{$q}%");

                    if (!empty($externalMovementIds)) {
                        $sub->orWhereIn('id', $externalMovementIds);
                    }

                    if (!empty($byProductPvaIds)) {
                        $sub->orWhereExists(function ($ex) use ($byProductPvaIds) {
                            $ex->select(DB::raw(1))
                                ->from('mouvement_pva')
                                ->whereColumn('mouvement_pva.mouvement_id', 'mouvements.id')
                                ->whereNull('mouvement_pva.deleted_at')
                                ->whereIn('mouvement_pva.product_variation_attribute_id', $byProductPvaIds);
                        });
                    }
                });
            }
        }

        // 11. Compute SQL KPI Summary for the current filtered dataset (before pagination)
        $summaryMovementSubquery = (clone $query)->where('statut', 1)->select('id', 'from_warehouse', 'to_warehouse');
        $kpiRows = DB::table('mouvement_pva as mp')
            ->joinSub($summaryMovementSubquery, 'm', function ($join) {
                $join->on('m.id', '=', 'mp.mouvement_id');
            })
            ->whereNull('mp.deleted_at')
            ->when($activeFilterPvaIds !== null && !empty($activeFilterPvaIds), function ($q) use ($activeFilterPvaIds) {
                $q->whereIn('mp.product_variation_attribute_id', $activeFilterPvaIds);
            })
            ->selectRaw("
                COALESCE(SUM(CASE WHEN m.to_warehouse IS NOT NULL AND m.from_warehouse IS NULL THEN mp.quantity ELSE 0 END), 0) as total_in_qty,
                COALESCE(SUM(CASE WHEN m.from_warehouse IS NOT NULL AND m.to_warehouse IS NULL THEN mp.quantity ELSE 0 END), 0) as total_out_qty,
                COALESCE(SUM(CASE WHEN m.from_warehouse IS NOT NULL AND m.to_warehouse IS NOT NULL THEN mp.quantity ELSE 0 END), 0) as total_transfer_qty
            ")
            ->first();

        $totalInQty = (float) ($kpiRows->total_in_qty ?? 0);
        $totalOutQty = (float) ($kpiRows->total_out_qty ?? 0);
        $totalTransferQty = (float) ($kpiRows->total_transfer_qty ?? 0);

        $stockWarehouseScope = !empty($expandedWarehouseIds) ? $expandedWarehouseIds : $accountWarehouseIds;
        $currentStockQty = (float) WarehousePva::whereIn('warehouse_id', $stockWarehouseScope)
            ->when($activeFilterPvaIds !== null, function ($q) use ($activeFilterPvaIds) {
                if (empty($activeFilterPvaIds)) {
                    $q->whereRaw('1 = 0');
                } else {
                    $q->whereIn('product_variation_attribute_id', $activeFilterPvaIds);
                }
            })
            ->sum('quantity');

        // 12. Sorting & Direct SQL Pagination
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? $params['pagination']['per_page'] ?? 10)));
        $currentPageZeroIndexed = max(0, (int) ($params['pagination']['current_page'] ?? (isset($params['page']) ? ((int) $params['page'] - 1) : 0)));

        $sortColumn = 'created_at';
        $sortOrder = 'DESC';
        if (!empty($params['sort'][0]['column'])) {
            $candidateCol = (string) $params['sort'][0]['column'];
            if (in_array($candidateCol, ['id', 'code', 'created_at', 'updated_at', 'statut', 'mouvement_type_id'], true)) {
                $sortColumn = $candidateCol;
            }
            $sortOrder = strtoupper((string) ($params['sort'][0]['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        } elseif (!empty($params['sort_by'])) {
            $candidateCol = (string) $params['sort_by'];
            if (in_array($candidateCol, ['id', 'code', 'created_at', 'updated_at', 'statut', 'mouvement_type_id'], true)) {
                $sortColumn = $candidateCol;
            }
            $sortOrder = strtoupper((string) ($params['sort_dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        }

        $total = (clone $query)->count();

        $mouvements = $query
            ->with([
                'mouvementType',
                'fromWarehouse',
                'toWarehouse',
                'mouvementPvas',
                'productVariationAttributes.product.images',
                'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                'accountUser.user.images',
            ])
            ->orderBy($sortColumn, $sortOrder)
            ->orderBy('id', 'DESC')
            ->skip($currentPageZeroIndexed * $perPage)
            ->take($perPage)
            ->get();

        $movementIds = $mouvements->pluck('id')->toArray();

        $pickups = !empty($movementIds)
            ? Pickup::with([
                'carrier:id,title',
                'warehouse:id,title',
                'orders:id,code,pickup_id,order_status_id',
            ])
                ->whereIn('mouvement_id', $movementIds)
                ->get()
                ->keyBy('mouvement_id')
            : collect();

        $supplierReceipts = !empty($movementIds)
            ? SupplierReceipt::with([
                'supplier:id,title',
                'warehouse:id,title',
                'supplierOrderPvas.orderAllocations.orderLine.supplierOrder:id,code,statut',
            ])
                ->whereIn('mouvement_id', $movementIds)
                ->get()
                ->keyBy('mouvement_id')
            : collect();

        $shipments = !empty($movementIds)
            ? Shipment::with([
                'carrier:id,title',
                'warehouse:id,title',
                'shipmentType:id,title',
                'orders:id,code,shipment_id,order_status_id',
            ])
                ->whereIn('mouvement_id', $movementIds)
                ->get()
                ->keyBy('mouvement_id')
            : collect();

        $activeFilterPvaLookup = $activeFilterPvaIds !== null ? array_fill_keys($activeFilterPvaIds, true) : null;

        $rows = $mouvements->map(function ($mouvement) use ($pickups, $supplierReceipts, $shipments, $warehouseDisplayMap, $activeFilterPvaLookup) {
            $pickup = $pickups->get($mouvement->id);
            $supplierReceipt = $supplierReceipts->get($mouvement->id);
            $shipment = $shipments->get($mouvement->id);

            $sourceType = 'manual';
            $source = null;
            $receiptLineMetaByPva = [];

            if ($supplierReceipt) {
                $sourceType = 'supplier_receipt';
                $linkedOrdersMap = [];
                $totalAllocatedQty = 0.0;
                $totalHorsCommandeQty = 0.0;

                foreach ($supplierReceipt->supplierOrderPvas as $rLine) {
                    $pvaId = (int) $rLine->product_variation_attribute_id;
                    $lineHorsCmd = (float) ($rLine->hors_commande_quantity ?? 0);
                    $lineAllocQty = 0.0;
                    $lineOrderCodes = [];

                    foreach ($rLine->orderAllocations as $alloc) {
                        $allocQty = (float) $alloc->allocated_quantity;
                        $lineAllocQty += $allocQty;
                        $so = $alloc->orderLine?->supplierOrder;
                        if ($so) {
                            $lineOrderCodes[] = $so->code;
                            if (!isset($linkedOrdersMap[$so->id])) {
                                $linkedOrdersMap[$so->id] = [
                                    'id' => $so->id,
                                    'code' => $so->code,
                                    'statut' => (int) $so->statut,
                                    'allocated_quantity' => 0.0,
                                ];
                            }
                            $linkedOrdersMap[$so->id]['allocated_quantity'] += $allocQty;
                        }
                    }

                    $totalAllocatedQty += $lineAllocQty;
                    $totalHorsCommandeQty += $lineHorsCmd;

                    $receiptLineMetaByPva[$pvaId] = [
                        'allocated_quantity' => $lineAllocQty,
                        'hors_commande_quantity' => $lineHorsCmd,
                        'order_codes' => array_values(array_unique($lineOrderCodes)),
                    ];
                }

                $source = [
                    'id' => $supplierReceipt->id,
                    'code' => $supplierReceipt->code,
                    'statut' => (int) $supplierReceipt->statut,
                    'supplier' => $supplierReceipt->supplier ? $supplierReceipt->supplier->only(['id', 'title']) : null,
                    'warehouse' => $supplierReceipt->warehouse ? $supplierReceipt->warehouse->only(['id', 'title']) : null,
                    'linked_orders' => array_values($linkedOrdersMap),
                    'allocated_quantity' => $totalAllocatedQty,
                    'hors_commande_quantity' => $totalHorsCommandeQty,
                ];
            } elseif ($pickup) {
                $sourceType = 'pickup';
                $pickupOrderCodes = $pickup->orders
                    ? $pickup->orders->pluck('code')->filter()->unique()->values()->toArray()
                    : [];
                $source = [
                    'id' => $pickup->id,
                    'code' => $pickup->code,
                    'statut' => (int) $pickup->statut,
                    'carrier' => $pickup->carrier ? $pickup->carrier->only(['id', 'title']) : null,
                    'warehouse' => $pickup->warehouse ? $pickup->warehouse->only(['id', 'title']) : null,
                    'orders_count' => count($pickupOrderCodes),
                    'order_codes' => array_slice($pickupOrderCodes, 0, 20),
                ];
            } elseif ($shipment) {
                $isReturn = ($shipment->shipment_type_id == 2)
                    || str_contains(strtolower($shipment->code ?? ''), 'ret')
                    || !empty($mouvement->to_warehouse);
                $sourceType = $isReturn ? 'return' : 'shipment';
                $shipmentOrderCodes = $shipment->orders
                    ? $shipment->orders->pluck('code')->filter()->unique()->values()->toArray()
                    : [];
                $source = [
                    'id' => $shipment->id,
                    'code' => $shipment->code,
                    'statut' => (int) $shipment->statut,
                    'carrier' => $shipment->carrier ? $shipment->carrier->only(['id', 'title']) : null,
                    'warehouse' => $shipment->warehouse ? $shipment->warehouse->only(['id', 'title']) : null,
                    'shipment_type' => $shipment->shipmentType ? $shipment->shipmentType->only(['id', 'title']) : null,
                    'orders_count' => count($shipmentOrderCodes),
                    'order_codes' => array_slice($shipmentOrderCodes, 0, 20),
                ];
            } elseif (str_starts_with($mouvement->description ?? '', "Ajustement d'inventaire:")) {
                $sourceType = 'adjustment';
                $source = [
                    'id' => $mouvement->id,
                    'code' => $mouvement->code,
                    'reason' => trim(str_replace("Ajustement d'inventaire:", '', (string) $mouvement->description)),
                ];
            } elseif (!empty($mouvement->from_warehouse) && !empty($mouvement->to_warehouse)) {
                $sourceType = 'transfer';
                $source = [
                    'id' => $mouvement->id,
                    'code' => $mouvement->code,
                ];
            }

            $hasRemove = !empty($mouvement->from_warehouse);
            $hasAdd = !empty($mouvement->to_warehouse);

            $stockEffect = 'none';
            if ($hasAdd && $hasRemove) {
                $stockEffect = 'transfer';
            } elseif ($hasAdd) {
                $stockEffect = 'add';
            } elseif ($hasRemove) {
                $stockEffect = 'remove';
            }

            $productVariations = $mouvement->productVariationAttributes->map(function ($productVariationAttribute) use ($receiptLineMetaByPva, $activeFilterPvaLookup) {
                $pvaId = (int) $productVariationAttribute->id;
                $meta = $receiptLineMetaByPva[$pvaId] ?? null;

                $pvaData = [];
                $pvaData['id'] = $pvaId;
                $pvaData['product'] = $productVariationAttribute->product ? ($productVariationAttribute->product->title ?: $productVariationAttribute->product->reference) : null;
                $pvaData['product_reference'] = $productVariationAttribute->product ? $productVariationAttribute->product->reference : null;
                $pvaData['product_id'] = $productVariationAttribute->product ? $productVariationAttribute->product->id : null;
                $pvaData['images'] = $productVariationAttribute->product ? $productVariationAttribute->product->images : [];
                $pvaData['quantity'] = (float) $productVariationAttribute->pivot->quantity;
                $pvaData['price'] = (float) $productVariationAttribute->pivot->price;
                $pvaData['allocated_quantity'] = $meta['allocated_quantity'] ?? null;
                $pvaData['hors_commande_quantity'] = $meta['hors_commande_quantity'] ?? null;
                $pvaData['order_codes'] = $meta['order_codes'] ?? [];
                $pvaData['matched_filter'] = $activeFilterPvaLookup === null ? true : isset($activeFilterPvaLookup[$pvaId]);
                $pvaData['variations'] = $productVariationAttribute->variationAttribute
                    ? $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                            return [
                                'id' => $childVariationAttribute->id,
                                'attribute_id' => $childVariationAttribute->attribute->id,
                                'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                'value' => $childVariationAttribute->attribute->title,
                            ];
                        }
                        return null;
                    })->filter()->values()
                    : [];

                return $pvaData;
            })->values();

            // When a product/variation filter is active, sort matched variations first and compute filtered totals
            if ($activeFilterPvaLookup !== null) {
                $productVariations = $productVariations->sortByDesc(fn ($item) => $item['matched_filter'] ? 1 : 0)->values();
            }

            $totalQuantity = (float) $mouvement->mouvementPvas->sum('quantity');
            $totalAmount = (float) $mouvement->mouvementPvas->sum(function ($line) {
                return ((float) $line->quantity) * ((float) $line->price);
            });

            $filteredQuantity = $activeFilterPvaLookup !== null
                ? (float) $productVariations->where('matched_filter', true)->sum('quantity')
                : $totalQuantity;
            $filteredAmount = $activeFilterPvaLookup !== null
                ? (float) $productVariations->where('matched_filter', true)->sum(fn ($item) => ((float) $item['quantity']) * ((float) $item['price']))
                : $totalAmount;

            $fromWhInfo = $mouvement->from_warehouse && isset($warehouseDisplayMap[(int) $mouvement->from_warehouse])
                ? [
                    'id' => $mouvement->fromWarehouse?->id ?? $mouvement->from_warehouse,
                    'title' => $warehouseDisplayMap[(int) $mouvement->from_warehouse]['root_title'],
                    'sub_title' => $mouvement->fromWarehouse?->title,
                ]
                : $mouvement->fromWarehouse;

            $toWhInfo = $mouvement->to_warehouse && isset($warehouseDisplayMap[(int) $mouvement->to_warehouse])
                ? [
                    'id' => $mouvement->toWarehouse?->id ?? $mouvement->to_warehouse,
                    'title' => $warehouseDisplayMap[(int) $mouvement->to_warehouse]['root_title'],
                    'sub_title' => $mouvement->toWarehouse?->title,
                ]
                : $mouvement->toWarehouse;

            return [
                'id' => $mouvement->id,
                'code' => $mouvement->code,
                'description' => $mouvement->description,
                'statut' => $mouvement->statut,
                'created_at' => $mouvement->created_at,
                'updated_at' => $mouvement->updated_at,
                'source_type' => $sourceType,
                'source' => $source,
                'mouvement_type' => $mouvement->mouvementType,
                'from_warehouse' => $fromWhInfo,
                'to_warehouse' => $toWhInfo,
                'stock_effect' => $stockEffect,
                'adds_stock' => $hasAdd,
                'removes_stock' => $hasRemove,
                'total_quantity' => $totalQuantity,
                'total_amount' => $totalAmount,
                'filtered_quantity' => $filteredQuantity,
                'filtered_amount' => $filteredAmount,
                'has_active_product_filter' => $activeFilterPvaLookup !== null,
                'user' => $mouvement->accountUser && $mouvement->accountUser->user ? [
                    'id' => $mouvement->accountUser->user->id,
                    'firstname' => $mouvement->accountUser->user->firstname,
                    'lastname' => $mouvement->accountUser->user->lastname,
                    'images' => $mouvement->accountUser->user->images,
                ] : null,
                'productVariations' => $productVariations,
            ];
        })->values();

        $filterCatalog = $this->buildFilterCatalog($accountUserIds, $accountWarehouseIds);

        return response()->json([
            'statut' => 1,
            'data' => $rows,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $currentPageZeroIndexed + 1,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $currentPageZeroIndexed + 1,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'summary' => [
                'total_movements' => $total,
                'total_in_qty' => $totalInQty,
                'total_out_qty' => $totalOutQty,
                'total_transfer_qty' => $totalTransferQty,
                'net_qty' => $totalInQty - $totalOutQty,
                'current_stock_qty' => $currentStockQty,
                'is_filtered_by_product' => $activeFilterPvaIds !== null,
            ],
            'filter_catalog' => $filterCatalog,
        ]);
    }
}
