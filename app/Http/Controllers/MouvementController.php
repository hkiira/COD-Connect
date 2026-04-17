<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Mouvement;
use App\Models\MouvementPva;
use App\Models\Pickup;
use App\Models\ProductVariationAttribute;
use App\Models\Shipment;
use App\Models\SupplierReceipt;
use Illuminate\Http\Request;

class MouvementController extends Controller
{
    public function index(Request $request)
    {
        $searchIds = null;
        $request = collect($request->query())->toArray();
        $accountUserIds = AccountUser::where('account_id', getAccountUser()->account_id)->pluck('id')->toArray();

        if (isset($request['suppliers']) && array_filter($request['suppliers'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $supplierIds = array_map('intval', $request['suppliers']);
            $supplierMovementIds = SupplierReceipt::whereIn('supplier_id', $supplierIds)
                ->whereNotNull('mouvement_id')
                ->pluck('mouvement_id')
                ->unique()
                ->toArray();
            $searchIds = is_null($searchIds) ? $supplierMovementIds : array_values(array_intersect($searchIds, $supplierMovementIds));
        }

        if (isset($request['carriers']) && array_filter($request['carriers'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $carrierIds = array_map('intval', $request['carriers']);
            $pickupMovementIds = Pickup::whereIn('carrier_id', $carrierIds)
                ->whereNotNull('mouvement_id')
                ->pluck('mouvement_id')
                ->toArray();
            $shipmentMovementIds = Shipment::whereIn('carrier_id', $carrierIds)
                ->whereNotNull('mouvement_id')
                ->pluck('mouvement_id')
                ->toArray();
            $carrierMovementIds = collect(array_merge($pickupMovementIds, $shipmentMovementIds))->unique()->values()->toArray();
            $searchIds = is_null($searchIds) ? $carrierMovementIds : array_values(array_intersect($searchIds, $carrierMovementIds));
        }

        if (isset($request['warehouses']) && array_filter($request['warehouses'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $warehouseIds = array_map('intval', $request['warehouses']);
            $warehouseMovementIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                ->where(function ($query) use ($warehouseIds) {
                    $query->whereIn('from_warehouse', $warehouseIds)
                        ->orWhereIn('to_warehouse', $warehouseIds);
                })
                ->pluck('id')
                ->toArray();
            $searchIds = is_null($searchIds) ? $warehouseMovementIds : array_values(array_intersect($searchIds, $warehouseMovementIds));
        }

        if (isset($request['productPvas']) && array_filter($request['productPvas'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $productPvaIds = array_map('intval', $request['productPvas']);
            $productPvaMovementIds = MouvementPva::whereIn('product_variation_attribute_id', $productPvaIds)
                ->pluck('mouvement_id')
                ->unique()
                ->toArray();
            $searchIds = is_null($searchIds) ? $productPvaMovementIds : array_values(array_intersect($searchIds, $productPvaMovementIds));
        }

        if (isset($request['products']) && array_filter($request['products'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $productIds = array_map('intval', $request['products']);
            $productPvaIds = ProductVariationAttribute::whereIn('product_id', $productIds)->pluck('id')->toArray();
            $productMovementIds = MouvementPva::whereIn('product_variation_attribute_id', $productPvaIds)
                ->pluck('mouvement_id')
                ->unique()
                ->toArray();
            $searchIds = is_null($searchIds) ? $productMovementIds : array_values(array_intersect($searchIds, $productMovementIds));
        }

        if (isset($request['mouvement_types']) && array_filter($request['mouvement_types'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $request['whereArrayMouvementType'] = ['column' => 'mouvement_type_id', 'values' => array_map('intval', $request['mouvement_types'])];
        }

        if (isset($request['mouvement_type_id']) && $request['mouvement_type_id'] !== '') {
            $request['where'] = ['column' => 'mouvement_type_id', 'value' => (int) $request['mouvement_type_id']];
        }

        if (isset($request['from_warehouses']) && array_filter($request['from_warehouses'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $request['whereArrayFromWarehouse'] = ['column' => 'from_warehouse', 'values' => array_map('intval', $request['from_warehouses'])];
        }

        if (isset($request['to_warehouses']) && array_filter($request['to_warehouses'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $request['whereArrayToWarehouse'] = ['column' => 'to_warehouse', 'values' => array_map('intval', $request['to_warehouses'])];
        }

        if (isset($request['stock_action']) && $request['stock_action'] !== '') {
            $stockAction = strtolower((string) $request['stock_action']);
            $stockIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                ->when($stockAction === 'add', function ($query) {
                    $query->whereNotNull('to_warehouse')->whereNull('from_warehouse');
                })
                ->when($stockAction === 'remove', function ($query) {
                    $query->whereNotNull('from_warehouse')->whereNull('to_warehouse');
                })
                ->when($stockAction === 'transfer', function ($query) {
                    $query->whereNotNull('from_warehouse')->whereNotNull('to_warehouse');
                })
                ->when($stockAction === 'none', function ($query) {
                    $query->whereNull('from_warehouse')->whereNull('to_warehouse');
                })
                ->pluck('id')
                ->toArray();
            $searchIds = is_null($searchIds) ? $stockIds : array_values(array_intersect($searchIds, $stockIds));
        }

        if (isset($request['source_types']) && array_filter($request['source_types'], function ($value) {
            return $value !== null && $value !== '';
        })) {
            $sourceTypes = array_map('strtolower', $request['source_types']);
            $pickupSourceIds = Pickup::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();
            $supplierReceiptSourceIds = SupplierReceipt::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();
            $shipmentSourceIds = Shipment::whereNotNull('mouvement_id')->pluck('mouvement_id')->toArray();

            $sourceIds = [];
            if (in_array('pickup', $sourceTypes)) {
                $sourceIds = array_merge($sourceIds, $pickupSourceIds);
            }
            if (in_array('supplier_receipt', $sourceTypes)) {
                $sourceIds = array_merge($sourceIds, $supplierReceiptSourceIds);
            }
            if (in_array('shipment', $sourceTypes)) {
                $sourceIds = array_merge($sourceIds, $shipmentSourceIds);
            }
            if (in_array('manual', $sourceTypes)) {
                $knownSourceIds = collect(array_merge($pickupSourceIds, $supplierReceiptSourceIds, $shipmentSourceIds))->unique()->values()->toArray();
                $manualIds = Mouvement::whereIn('account_user_id', $accountUserIds)
                    ->when(!empty($knownSourceIds), function ($query) use ($knownSourceIds) {
                        $query->whereNotIn('id', $knownSourceIds);
                    })
                    ->pluck('id')
                    ->toArray();
                $sourceIds = array_merge($sourceIds, $manualIds);
            }

            $sourceIds = collect($sourceIds)->unique()->values()->toArray();
            $searchIds = is_null($searchIds) ? $sourceIds : array_values(array_intersect($searchIds, $sourceIds));
        }

        if (!is_null($searchIds)) {
            $request['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (isset($request['date_from']) || isset($request['date_to'])) {
            $request['startDate'] = $request['date_from'] ?? null;
            $request['endDate'] = $request['date_to'] ?? null;
        }

        if (isset($request['sort_by'])) {
            $request['sort'] = [[
                'column' => $request['sort_by'],
                'order' => strtoupper(($request['sort_dir'] ?? 'DESC')),
            ]];
        }

        if (isset($request['per_page'])) {
            $request['pagination']['per_page'] = $request['per_page'];
        }

        $associated = [];
        $associated[] = [
            'model' => 'App\\Models\\MouvementType',
            'title' => 'mouvementType',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Warehouse',
            'title' => 'fromWarehouse',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\Warehouse',
            'title' => 'toWarehouse',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\MouvementPva',
            'title' => 'mouvementPvas',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\ProductVariationAttribute',
            'title' => 'productVariationAttributes.product',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\ProductVariationAttribute',
            'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            'search' => false,
        ];
        $associated[] = [
            'model' => 'App\\Models\\AccountUser',
            'title' => 'accountUser.user',
            'search' => false,
        ];
        $filters = HelperFunctions::filterColumns($request, ['id', 'code']);
        $model = 'App\\Models\\Mouvement';
        $request['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
        $datas = FilterController::searchs(new Request($request), $model, ['id', 'code'], false, $associated);

        $datas = collect($datas);

        if (isset($request['whereArrayMouvementType'])) {
            $datas = $datas->whereIn('mouvement_type_id', $request['whereArrayMouvementType']['values']);
        }
        if (isset($request['whereArrayFromWarehouse'])) {
            $datas = $datas->whereIn('from_warehouse', $request['whereArrayFromWarehouse']['values']);
        }
        if (isset($request['whereArrayToWarehouse'])) {
            $datas = $datas->whereIn('to_warehouse', $request['whereArrayToWarehouse']['values']);
        }

        $movementIds = $datas->pluck('id')->toArray();

        $pickups = Pickup::with(['carrier:id,title', 'warehouse:id,title'])
            ->whereIn('mouvement_id', $movementIds)
            ->get()
            ->keyBy('mouvement_id');

        $supplierReceipts = SupplierReceipt::with(['supplier:id,title', 'warehouse:id,title'])
            ->whereIn('mouvement_id', $movementIds)
            ->get()
            ->keyBy('mouvement_id');

        $shipments = Shipment::with(['carrier:id,title', 'warehouse:id,title', 'shipmentType:id,title'])
            ->whereIn('mouvement_id', $movementIds)
            ->get()
            ->keyBy('mouvement_id');

        $datas = $datas->map(function ($mouvement) use ($pickups, $supplierReceipts, $shipments) {
            $pickup = $pickups->get($mouvement->id);
            $supplierReceipt = $supplierReceipts->get($mouvement->id);
            $shipment = $shipments->get($mouvement->id);

            $sourceType = 'manual';
            $source = null;

            if ($supplierReceipt) {
                $sourceType = 'supplier_receipt';
                $source = [
                    'id' => $supplierReceipt->id,
                    'code' => $supplierReceipt->code,
                    'supplier' => $supplierReceipt->supplier ? $supplierReceipt->supplier->only(['id', 'title']) : null,
                    'warehouse' => $supplierReceipt->warehouse ? $supplierReceipt->warehouse->only(['id', 'title']) : null,
                ];
            } elseif ($pickup) {
                $sourceType = 'pickup';
                $source = [
                    'id' => $pickup->id,
                    'code' => $pickup->code,
                    'carrier' => $pickup->carrier ? $pickup->carrier->only(['id', 'title']) : null,
                    'warehouse' => $pickup->warehouse ? $pickup->warehouse->only(['id', 'title']) : null,
                ];
            } elseif ($shipment) {
                $sourceType = 'shipment';
                $source = [
                    'id' => $shipment->id,
                    'code' => $shipment->code,
                    'carrier' => $shipment->carrier ? $shipment->carrier->only(['id', 'title']) : null,
                    'warehouse' => $shipment->warehouse ? $shipment->warehouse->only(['id', 'title']) : null,
                    'shipment_type' => $shipment->shipmentType ? $shipment->shipmentType->only(['id', 'title']) : null,
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

            $totalQuantity = (float) $mouvement->mouvementPvas->sum('quantity');
            $totalAmount = (float) $mouvement->mouvementPvas->sum(function ($line) {
                return ((float) $line->quantity) * ((float) $line->price);
            });

            $mouvementData = $mouvement;
            $mouvementData['source_type'] = $sourceType;
            $mouvementData['source'] = $source;
            $mouvementData['mouvement_type'] = $mouvement->mouvementType;
            $mouvementData['from_warehouse'] = $mouvement->fromWarehouse;
            $mouvementData['to_warehouse'] = $mouvement->toWarehouse;
            $mouvementData['stock_effect'] = $stockEffect;
            $mouvementData['adds_stock'] = $hasAdd;
            $mouvementData['removes_stock'] = $hasRemove;
            $mouvementData['total_quantity'] = $totalQuantity;
            $mouvementData['total_amount'] = $totalAmount;
            $mouvementData['user'] = $mouvement->accountUser && $mouvement->accountUser->user ? [
                'id' => $mouvement->accountUser->user->id,
                'firstname' => $mouvement->accountUser->user->firstname,
                'lastname' => $mouvement->accountUser->user->lastname,
                'images' => $mouvement->accountUser->user->images,
            ] : null;
            $mouvementData['productVariations'] = $mouvement->productVariationAttributes->map(function ($productVariationAttribute) {
                $pvaData['id'] = $productVariationAttribute->id;
                $pvaData['product'] = $productVariationAttribute->product ? $productVariationAttribute->product->reference : null;
                $pvaData['product_id'] = $productVariationAttribute->product ? $productVariationAttribute->product->id : null;
                $pvaData['quantity'] = $productVariationAttribute->pivot->quantity;
                $pvaData['price'] = $productVariationAttribute->pivot->price;
                $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                        return [
                            'id' => $childVariationAttribute->id,
                            'type' => $childVariationAttribute->attribute->typeAttribute->title,
                            'value' => $childVariationAttribute->attribute->title,
                        ];
                    }
                })->filter()->values();

                return $pvaData;
            })->values();

            return $mouvementData;
        });

        return HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }
}
