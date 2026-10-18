<?php

namespace App\Http\Controllers;

use App\Models\AccountUser;
use App\Models\Product;
use App\Models\ProductVariationAttribute;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderPva;
use App\Models\SupplierPva;
use App\Models\SupplierReceipt;
use App\Models\Warehouse;
use App\Services\SupplierProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SupplierReceiptController extends Controller
{
    public function index(Request $request)
    {
        $requestData = collect($request->query())->toArray();
        $searchIds = null;

        if (isset($requestData['suppliers']) && array_filter($requestData['suppliers'], fn ($value) => $value !== null)) {
            $supplierIds = array_values(array_filter($requestData['suppliers'], fn ($v) => $v !== null));
            $ids = SupplierReceipt::whereIn('supplier_id', $supplierIds)->pluck('id')->toArray();
            $searchIds = $searchIds === null ? $ids : array_values(array_intersect($searchIds, $ids));
        }

        if (isset($requestData['warehouses']) && array_filter($requestData['warehouses'], fn ($value) => $value !== null)) {
            $warehouseIds = array_values(array_filter($requestData['warehouses'], fn ($v) => $v !== null));
            $ids = SupplierReceipt::whereIn('warehouse_id', $warehouseIds)->pluck('id')->toArray();
            $searchIds = $searchIds === null ? $ids : array_values(array_intersect($searchIds, $ids));
        }

        if ($searchIds !== null) {
            $requestData['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        $associated = [];
        $filters = HelperFunctions::filterColumns($requestData, ['id', 'code']);
        $model = 'App\\Models\\SupplierReceipt';
        $requestData['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];

        $datas = FilterController::searchs(new Request($requestData), $model, ['id', 'code'], false, $associated);

        $datas = $datas->map(function (SupplierReceipt $supplierReceipt) {
            $productData = $supplierReceipt->only('id', 'code', 'supplier_id', 'warehouse_id', 'mouvement_id', 'created_at', 'updated_at', 'statut');
            $productData['user'] = $supplierReceipt->accountUser && $supplierReceipt->accountUser->user ? [
                'id' => $supplierReceipt->accountUser->user->id,
                'firstname' => $supplierReceipt->accountUser->user->firstname,
                'lastname' => $supplierReceipt->accountUser->user->lastname,
                'images' => $supplierReceipt->accountUser->user->images,
            ] : null;

            $receiptLines = $supplierReceipt->supplierReceiptPvas()
                ->whereNull('supplier_order_id')
                ->with([
                    'productVariationAttribute.product.images',
                    'productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                    'receiptAllocations.supplierOrder',
                ])
                ->get();

            $total = 0;
            $quantity = 0;
            $totalAllocated = 0;
            $totalHorsCommande = 0;
            $ordersMap = [];

            $productData['supplier'] = $supplierReceipt->supplier;
            if ($productData['supplier']) {
                $productData['supplier']['images'] = $supplierReceipt->supplier->images ?? [];
            }
            $productData['warehouse'] = $supplierReceipt->warehouse;

            $productData['productVariations'] = $receiptLines->map(function (SupplierOrderPva $receiptLine) use (
                &$total,
                &$quantity,
                &$totalAllocated,
                &$totalHorsCommande,
                &$ordersMap,
                $supplierReceipt
            ) {
                $pva = $receiptLine->productVariationAttribute;
                $product = $pva ? $pva->product : null;
                $firstImage = $product && $product->images ? $product->images->sortByDesc('created_at')->first() : null;
                $imagePath = $firstImage ? ($firstImage->photo_dir . $firstImage->photo) : null;

                $lineQty = max(0, (int) $receiptLine->quantity);
                $lineAllocations = [];
                $lineAllocatedSum = 0;

                foreach ($receiptLine->receiptAllocations as $alloc) {
                    $ord = $alloc->supplierOrder;
                    $allocQty = (int) $alloc->allocated_quantity;
                    $lineAllocatedSum += $allocQty;
                    if ($ord) {
                        $lineAllocations[] = [
                            'order_id' => $ord->id,
                            'order_code' => $ord->code,
                            'order_statut' => $ord->statut,
                            'allocated_quantity' => $allocQty,
                        ];
                        if (!isset($ordersMap[$ord->id])) {
                            $ordersMap[$ord->id] = [
                                'id' => $ord->id,
                                'code' => $ord->code,
                                'statut' => $ord->statut,
                                'allocated_quantity' => 0,
                            ];
                        }
                        $ordersMap[$ord->id]['allocated_quantity'] += $allocQty;
                    }
                }

                $isValidated = (int) $supplierReceipt->statut >= SupplierReceipt::STATUS_VALIDATED;
                $lineHorsCommande = $isValidated
                    ? max(0, $lineQty - $lineAllocatedSum)
                    : 0;

                $total += $lineQty * (float) $receiptLine->price;
                $quantity += $lineQty;
                $totalAllocated += $lineAllocatedSum;
                $totalHorsCommande += $lineHorsCommande;

                $variations = ($pva && $pva->variationAttribute && $pva->variationAttribute->childVariationAttributes)
                    ? $pva->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                            return [
                                'id' => $childVariationAttribute->id,
                                'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                'value' => $childVariationAttribute->attribute->title,
                            ];
                        }
                        return null;
                    })->filter()->values()
                    : [];

                return [
                    'id' => $pva ? $pva->id : $receiptLine->id,
                    'sop_id' => $receiptLine->id,
                    'product_id' => $product ? $product->id : null,
                    'product' => $product ? $product->reference : '',
                    'product_title' => $product ? $product->title : '',
                    'title' => $product ? $product->title : '',
                    'sku' => $product ? $product->reference : '',
                    'images' => $product && $product->images ? $product->images : [],
                    'image' => $imagePath,
                    'thumbnail_url' => $imagePath,
                    'quantity' => $lineQty,
                    'allocated_quantity' => $lineAllocatedSum,
                    'hors_commande_quantity' => $lineHorsCommande,
                    'price' => $receiptLine->price,
                    'variations' => $variations,
                    'allocations' => $lineAllocations,
                ];
            });

            $productData['total'] = $total;
            $productData['quantity'] = $quantity;
            $productData['allocated_quantity'] = $totalAllocated;
            $productData['hors_commande_quantity'] = $totalHorsCommande;
            $productData['orders'] = array_values($ordersMap);

            return $productData;
        });

        return HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function generatePdf($id)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        $orderPvas = SupplierOrderPva::where('supplier_receipt_id', $id)->whereNull('supplier_order_id')->get();
        $datas = [];
        $pickUpTotal = 0;
        foreach ($orderPvas as $orderPva) {
            $variations = $orderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVa) {
                return $childVa->attribute->title;
            });
            if ($orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first()) {
                $datas['datas'][$orderPva->productVariationAttribute->product->id]['image'] =
                    $orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first()->photo_dir .
                    $orderPva->productVariationAttribute->product->images->sortByDesc('created_at')->first()->photo;
            }
            $datas['datas'][$orderPva->productVariationAttribute->product->id]['product'] = $orderPva->productVariationAttribute->product->title;
            $datas['datas'][$orderPva->productVariationAttribute->product->id]['pvas'][] = [
                'variation' => implode(', ', $variations->toArray()),
                'quantity' => $orderPva->quantity,
                'price' => $orderPva->price,
            ];
            $pickUpTotal += $orderPva->quantity * $orderPva->price;
        }
        $datas['total'] = $pickUpTotal;
        $datas['datas'] = collect($datas['datas'] ?? [])->values()->toArray();
        $datas['orderFor'] = $supplierReceipt->supplier->title;
        $datas['account'] = $supplierReceipt->supplier->account->name;
        $datas['code'] = $supplierReceipt->code;
        $datas['countOrders'] = $orderPvas->count();
        $html = view('pdf.supplierReceipt', $datas)->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'P',
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S');

        return response()->json([
            'statut' => 1,
            'data' => base64_encode($pdfContent),
        ]);
    }

    /**
     * Return all open pending items across active SupplierOrders of a supplier
     * for 1-click import into a new Bon de Réception.
     */
    public function pendingItems(Request $request)
    {
        $supplierId = (int) $request->query('supplier_id', 0);
        $warehouseId = (int) $request->query('warehouse_id', 0);

        if ($supplierId <= 0) {
            return response()->json([
                'statut' => 1,
                'data' => [],
            ]);
        }

        $openLines = SupplierOrderPva::query()
            ->select('supplier_order_pva.*')
            ->join('supplier_orders as so', 'so.id', '=', 'supplier_order_pva.supplier_order_id')
            ->whereNull('so.deleted_at')
            ->whereNull('supplier_order_pva.deleted_at')
            ->whereNull('supplier_order_pva.supplier_receipt_id')
            ->where('so.supplier_id', $supplierId)
            ->whereIn('so.statut', [SupplierOrder::STATUS_PENDING, SupplierOrder::STATUS_PARTIAL])
            ->whereRaw('(supplier_order_pva.quantity - supplier_order_pva.received_quantity - supplier_order_pva.cancelled_quantity) > 0')
            ->orderByRaw('CASE WHEN ? > 0 AND so.warehouse_id = ? THEN 0 ELSE 1 END ASC', [$warehouseId, $warehouseId])
            ->orderBy('so.created_at', 'asc')
            ->orderBy('so.id', 'asc')
            ->with([
                'supplierOrder',
                'productVariationAttribute.product.images',
                'productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
            ])
            ->get();

        $grouped = [];

        foreach ($openLines as $line) {
            $pva = $line->productVariationAttribute;
            if (!$pva || !$pva->product) {
                continue;
            }
            $product = $pva->product;
            $pvaId = (int) $pva->id;
            $remaining = max(0, (int) $line->quantity - (int) $line->received_quantity - (int) $line->cancelled_quantity);

            if ($remaining <= 0) {
                continue;
            }

            if (!isset($grouped[$pvaId])) {
                $firstImage = $product->images ? $product->images->sortByDesc('created_at')->first() : null;
                $imagePath = $firstImage ? ($firstImage->photo_dir . $firstImage->photo) : null;

                $variations = ($pva->variationAttribute && $pva->variationAttribute->childVariationAttributes)
                    ? $pva->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                            return [
                                'id' => $childVariationAttribute->id,
                                'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                'value' => $childVariationAttribute->attribute->title,
                            ];
                        }
                        return null;
                    })->filter()->values()->toArray()
                    : [];

                $variationTitle = collect($variations)
                    ->map(fn ($v) => !empty($v['type']) ? "{$v['type']}: {$v['value']}" : ($v['value'] ?? ''))
                    ->filter()
                    ->implode(' / ');

                $grouped[$pvaId] = [
                    'id' => $pvaId,
                    'parent_id' => $product->id,
                    'product_id' => $product->id,
                    'product_title' => $product->title,
                    'variation_title' => $variationTitle,
                    'sku' => $product->reference ?? '',
                    'thumbnail_url' => $imagePath,
                    'attributes' => $variations,
                    'variations' => $variations,
                    'quantity' => 0,
                    'pending_order_qty' => 0,
                    'price' => (float) $line->price,
                    'order_codes' => [],
                ];
            }

            $grouped[$pvaId]['quantity'] += $remaining;
            $grouped[$pvaId]['pending_order_qty'] += $remaining;
            if ($line->supplierOrder && !in_array($line->supplierOrder->code, $grouped[$pvaId]['order_codes'], true)) {
                $grouped[$pvaId]['order_codes'][] = $line->supplierOrder->code;
            }
        }

        return response()->json([
            'statut' => 1,
            'data' => array_values($grouped),
        ]);
    }

    public function create(Request $request)
    {
        $requestData = collect($request->query())->toArray();
        $data = [];

        if (isset($requestData['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $supplierId = (int) ($requestData['products']['inactive']['supplier'] ?? 0);
            $searchQuery = trim((string) ($requestData['products']['inactive']['search'] ?? ''));

            // 1. All variations linked to this supplier via SupplierPva
            $supplierPvaMap = SupplierPva::where('supplier_id', $supplierId)
                ->pluck('price', 'product_variation_attribute_id')
                ->toArray();

            // 2. Open order quantities & prices for this supplier
            $openOrderRows = SupplierOrderPva::query()
                ->selectRaw('supplier_order_pva.product_variation_attribute_id as pva_id,
                    SUM(GREATEST(0, supplier_order_pva.quantity - supplier_order_pva.received_quantity - supplier_order_pva.cancelled_quantity)) as pending_qty,
                    MAX(supplier_order_pva.price) as latest_price')
                ->join('supplier_orders as so', 'so.id', '=', 'supplier_order_pva.supplier_order_id')
                ->whereNull('so.deleted_at')
                ->whereNull('supplier_order_pva.deleted_at')
                ->whereNull('supplier_order_pva.supplier_receipt_id')
                ->where('so.supplier_id', $supplierId)
                ->whereIn('so.statut', [SupplierOrder::STATUS_PENDING, SupplierOrder::STATUS_PARTIAL])
                ->groupBy('supplier_order_pva.product_variation_attribute_id')
                ->get()
                ->keyBy('pva_id');

            $supplierPvaIds = array_unique(array_merge(
                array_keys($supplierPvaMap),
                $openOrderRows->keys()->toArray()
            ));

            $productIds = ProductVariationAttribute::whereIn('id', $supplierPvaIds)->pluck('product_id')->unique()->toArray();

            $filters = HelperFunctions::filterColumns($requestData['products']['inactive'], ['id', 'code']);
            $requestData['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];

            // If user isn't searching globally, scope to supplier's products; if searching, allow matching any account product for Hors Commande!
            if ($searchQuery === '' && !empty($productIds)) {
                $requestData['products']['inactive']['whereArray'] = ['column' => 'id', 'values' => $productIds];
            }

            $associated = [
                [
                    'model' => 'App\\Models\\ProductVariationAttribute',
                    'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                    'search' => false,
                ],
                [
                    'model' => 'App\\Models\\ProductVariationAttribute',
                    'title' => 'productVariationAttributes.pvaPacks.childPvaPacks.ProductVariationAttribute.Product',
                    'search' => false,
                ],
            ];

            $products = FilterController::searchs(new Request($requestData['products']['inactive']), $model, ['id', 'title'], false, $associated)
                ->map(function ($product) use ($supplierPvaIds, $supplierPvaMap, $openOrderRows, $searchQuery) {
                    $productData = $product->only('id', 'title', 'reference');
                    $productData['productType'] = $product->productType;
                    $productData['images'] = $product->images ? [$product->images->sortByDesc('created_at')->first()] : [];
                    $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) use (
                        $product,
                        $supplierPvaIds,
                        $supplierPvaMap,
                        $openOrderRows,
                        $searchQuery
                    ) {
                        if ($product->product_type_id == 1) {
                            $pvaId = (int) $productVariationAttribute->id;
                            $isSupplierPva = in_array($pvaId, $supplierPvaIds, true);

                            // Include variation if it belongs to supplier OR if user is searching (Hors Commande)
                            if ($isSupplierPva || $searchQuery !== '') {
                                $openInfo = $openOrderRows->get($pvaId);
                                $pendingQty = $openInfo ? (int) $openInfo->pending_qty : 0;
                                $price = $openInfo && (float) $openInfo->latest_price > 0
                                    ? (float) $openInfo->latest_price
                                    : (float) ($supplierPvaMap[$pvaId] ?? 0);

                                $pvaData = [
                                    'id' => $pvaId,
                                    'price' => $price,
                                    'pending_order_qty' => $pendingQty,
                                    'is_hors_catalogue' => !$isSupplierPva,
                                ];
                                $pvaData['variations'] = ($productVariationAttribute->variationAttribute && $productVariationAttribute->variationAttribute->childVariationAttributes)
                                    ? $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                                        if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                                            return [
                                                'id' => $childVariationAttribute->id,
                                                'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                                'value' => $childVariationAttribute->attribute->title,
                                            ];
                                        }
                                        return null;
                                    })->filter()->values()
                                    : [];

                                return $pvaData;
                            }
                        }
                        return null;
                    })->filter()->values();

                    return $productData;
                })
                ->filter(fn ($p) => count($p['productVariations'] ?? []) > 0)
                ->values();

            $data['products']['inactive'] = HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($requestData['warehouses']['inactive'])) {
            $model = 'App\\Models\\Warehouse';
            $requestData['warehouses']['inactive']['inAccount'] = ['account_id', getAccountUser()->account_id];
            $requestData['warehouses']['inactive']['where'] = ['column' => 'warehouse_type_id', 'value' => 1];
            $data['warehouses']['inactive'] = FilterController::searchs(new Request($requestData['warehouses']['inactive']), $model, ['id', 'title'], true);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests, SupplierProcurementService $procurementService)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.supplier_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where(['id' => $value])->where('account_id', $account_id)->first();
                    if (!$titleModel) {
                        $fail('not exist');
                    }
                },
            ],
            '*.productVariationAttributes.*.id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $pva = ProductVariationAttribute::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$pva) {
                        $fail('not exist');
                    }
                },
            ],
            '*.warehouse_id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail('not exist');
                    }
                },
            ],
            '*.productVariationAttributes.*.quantity' => 'required|numeric|min:1',
            '*.productVariationAttributes.*.price' => 'numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $supplierReceipts = collect($requests->except('_method'))->map(function ($request) use ($procurementService) {
            return DB::transaction(function () use ($request, $procurementService) {
                $accountUser = getAccountUser();
                $accountId = $accountUser->account_id;

                $shouldValidate = !empty($request['validate']) || (isset($request['statut']) && (int) $request['statut'] >= 1);

                $supplierReceipt = SupplierReceipt::create([
                    'code' => DefaultCodeController::getAccountCode('SupplierReceipt', $accountId),
                    'warehouse_id' => $request['warehouse_id'],
                    'supplier_id' => $request['supplier_id'],
                    'statut' => $shouldValidate ? SupplierReceipt::STATUS_VALIDATED : SupplierReceipt::STATUS_DRAFT,
                    'account_user_id' => $accountUser->id,
                ]);

                if (isset($request['productVariationAttributes'])) {
                    foreach ($request['productVariationAttributes'] as $pvaData) {
                        $pva = SupplierPva::firstOrCreate(
                            [
                                'supplier_id' => $supplierReceipt->supplier_id,
                                'product_variation_attribute_id' => $pvaData['id'],
                            ],
                            [
                                'price' => $pvaData['price'] ?? 0,
                                'statut' => 1,
                                'account_id' => $accountId,
                            ]
                        );

                        SupplierOrderPva::create([
                            'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                            'supplier_order_id' => null,
                            'supplier_receipt_id' => $supplierReceipt->id,
                            'quantity' => (int) $pvaData['quantity'],
                            'received_quantity' => 0,
                            'cancelled_quantity' => 0,
                            'hors_commande_quantity' => 0,
                            'price' => isset($pvaData['price']) ? $pvaData['price'] : $pva->price,
                            'account_user_id' => $accountUser->id,
                            'sop_type_id' => 1,
                            'statut' => $shouldValidate ? 2 : 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                if ($shouldValidate) {
                    $procurementService->validateReceipt($supplierReceipt);
                }

                return SupplierReceipt::with(['supplier', 'warehouse'])->find($supplierReceipt->id);
            });
        });

        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipts,
        ]);
    }

    public function edit(Request $request, $id)
    {
        $requestData = collect($request->query())->toArray();
        $data = [];
        $supplierReceipt = SupplierReceipt::with(['warehouse', 'supplier.addresses.city', 'supplier.warehouses', 'supplier.phones.PhoneTypes'])->find($id);
        if (!$supplierReceipt) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        if (isset($requestData['supplierReceiptInfo'])) {
            $data['supplierReceiptInfo']['data'] = $supplierReceipt;
            $data['supplierReceiptInfo']['data']['principalImage'] = $supplierReceipt->supplier ? $supplierReceipt->supplier->images : [];
        }

        if (isset($requestData['products']['active'])) {
            $filters = HelperFunctions::filterColumns($requestData['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $receiptLines = SupplierOrderPva::where('supplier_receipt_id', $supplierReceipt->id)
                ->whereNull('supplier_order_id')
                ->with([
                    'productVariationAttribute.product.images',
                    'productVariationAttribute.product.productType',
                    'productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                    'receiptAllocations.supplierOrder',
                ])
                ->get();

            $pvas = [];
            $products = [];

            foreach ($receiptLines as $line) {
                $pva = $line->productVariationAttribute;
                if (!$pva || !$pva->product) {
                    continue;
                }
                $product = $pva->product;
                $variations = ($pva->variationAttribute && $pva->variationAttribute->childVariationAttributes)
                    ? $pva->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                            return [
                                'id' => $childVariationAttribute->id,
                                'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                'value' => $childVariationAttribute->attribute->title,
                            ];
                        }
                        return null;
                    })->filter()->values()
                    : [];

                $allocations = $line->receiptAllocations->map(fn ($a) => [
                    'order_id' => $a->supplier_order_id,
                    'order_code' => $a->supplierOrder?->code,
                    'allocated_quantity' => (int) $a->allocated_quantity,
                ])->values()->toArray();

                $pvas[$product->id][] = [
                    'id' => $pva->id,
                    'price' => $line->price,
                    'quantity' => $line->quantity,
                    'hors_commande_quantity' => (int) $line->hors_commande_quantity,
                    'allocations' => $allocations,
                    'variations' => $variations,
                ];

                $products[$product->id] = [
                    'id' => $product->id,
                    'title' => $product->title,
                    'reference' => $product->reference,
                    'created_at' => $product->created_at,
                    'statut' => $product->statut,
                    'images' => $product->images,
                    'productType' => $product->productType,
                    'productVariations' => $pvas[$product->id],
                ];
            }

            $data['products']['active'] = HelperFunctions::getPagination(collect($products), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function update(Request $requests, SupplierProcurementService $procurementService)
    {
        $validator = Validator::make($requests->except('_method'), [
            '*.id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $account_id = getAccountUser()->account_id;
                    $accountUsers = AccountUser::where(['account_id' => $account_id, 'statut' => 1])->pluck('id')->toArray();
                    $titleModel = SupplierReceipt::where(['id' => $value])->whereIn('account_user_id', $accountUsers)->first();
                    if (!$titleModel) {
                        $fail('not exist');
                    }
                },
            ],
            '*.supplier_id' => [
                'required',
                function ($attribute, $value, $fail) {
                    $account_id = getAccountUser()->account_id;
                    $titleModel = Supplier::where(['id' => $value])->where('account_id', $account_id)->first();
                    if (!$titleModel) {
                        $fail('not exist');
                    }
                },
            ],
            '*.warehouse_id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail('not exist');
                    }
                },
            ],
            '*.productVariationAttributes.*.id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $pva = ProductVariationAttribute::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$pva) {
                        $fail('not exist');
                    }
                },
            ],
            '*.productVariationAttributes.*.quantity' => 'required|numeric|min:1',
            '*.productVariationAttributes.*.price' => 'numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $supplierReceipts = collect($requests->except('_method'))->map(function ($request) use ($procurementService) {
            return DB::transaction(function () use ($request, $procurementService) {
                $supplierReceipt = SupplierReceipt::find($request['id']);
                $accountUser = getAccountUser();
                $accountId = $accountUser->account_id;

                // 1. If the receipt was previously validated, deallocate FIFO & roll back stock first
                if ((int) $supplierReceipt->statut >= SupplierReceipt::STATUS_VALIDATED) {
                    $procurementService->unvalidateReceipt($supplierReceipt);
                    $supplierReceipt = $supplierReceipt->fresh();
                }

                $shouldValidate = !empty($request['validate']) || (isset($request['statut']) && (int) $request['statut'] >= 1);

                $supplierReceipt->update([
                    'warehouse_id' => $request['warehouse_id'],
                    'supplier_id' => $request['supplier_id'],
                    'statut' => $shouldValidate ? SupplierReceipt::STATUS_VALIDATED : SupplierReceipt::STATUS_DRAFT,
                ]);

                if (isset($request['productVariationAttributes'])) {
                    $existingReceiptLines = SupplierOrderPva::where('supplier_receipt_id', $supplierReceipt->id)
                        ->whereNull('supplier_order_id')
                        ->get();

                    $incomingIds = collect($request['productVariationAttributes'])->pluck('id')->map(fn ($id) => (int) $id)->toArray();

                    foreach ($existingReceiptLines as $existingLine) {
                        if (!in_array((int) $existingLine->product_variation_attribute_id, $incomingIds, true)) {
                            $existingLine->delete();
                        }
                    }

                    foreach ($request['productVariationAttributes'] as $pvaData) {
                        $isExist = SupplierOrderPva::where([
                            'product_variation_attribute_id' => $pvaData['id'],
                            'supplier_receipt_id' => $supplierReceipt->id,
                        ])->whereNull('supplier_order_id')->first();

                        if ($isExist) {
                            $isExist->update([
                                'quantity' => isset($pvaData['quantity']) ? (int) $pvaData['quantity'] : $isExist->quantity,
                                'price' => isset($pvaData['price']) ? $pvaData['price'] : $isExist->price,
                                'hors_commande_quantity' => 0,
                                'statut' => $shouldValidate ? 2 : 1,
                            ]);
                        } else {
                            $pva = SupplierPva::firstOrCreate(
                                [
                                    'supplier_id' => $supplierReceipt->supplier_id,
                                    'product_variation_attribute_id' => $pvaData['id'],
                                ],
                                [
                                    'price' => $pvaData['price'] ?? 0,
                                    'statut' => 1,
                                    'account_id' => $accountId,
                                ]
                            );

                            SupplierOrderPva::create([
                                'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                                'supplier_order_id' => null,
                                'supplier_receipt_id' => $supplierReceipt->id,
                                'quantity' => (int) $pvaData['quantity'],
                                'received_quantity' => 0,
                                'cancelled_quantity' => 0,
                                'hors_commande_quantity' => 0,
                                'price' => isset($pvaData['price']) ? $pvaData['price'] : $pva->price,
                                'account_user_id' => $accountUser->id,
                                'sop_type_id' => 1,
                                'statut' => $shouldValidate ? 2 : 1,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ]);
                        }
                    }
                }

                // 2. If target status is Validated, apply stock + FIFO allocation
                if ($shouldValidate) {
                    $procurementService->validateReceipt($supplierReceipt->fresh());
                }

                return SupplierReceipt::with(['supplier', 'warehouse'])->find($supplierReceipt->id);
            });
        });

        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipts,
        ]);
    }

    /**
     * Validate a draft SupplierReceipt (POST api/supplier_receipts/{id}/validate)
     */
    public function validateReceiptAction($id, SupplierProcurementService $procurementService)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        if (!$supplierReceipt) {
            return response()->json([
                'statut' => 0,
                'message' => 'Bon de réception introuvable',
            ], 404);
        }

        $updated = $procurementService->validateReceipt($supplierReceipt);

        return response()->json([
            'statut' => 1,
            'data' => $updated,
            'message' => 'Bon de réception validé : stock et commandes fournisseurs mis à jour',
        ]);
    }

    /**
     * Unvalidate (Dévalider) a validated SupplierReceipt back to Draft (POST api/supplier_receipts/{id}/unvalidate)
     */
    public function unvalidateReceiptAction($id, SupplierProcurementService $procurementService)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        if (!$supplierReceipt) {
            return response()->json([
                'statut' => 0,
                'message' => 'Bon de réception introuvable',
            ], 404);
        }

        $updated = $procurementService->unvalidateReceipt($supplierReceipt);

        return response()->json([
            'statut' => 1,
            'data' => $updated,
            'message' => 'Validation annulée : stock et commandes fournisseurs rétablis',
        ]);
    }

    public static function syncStockForReceipt(SupplierReceipt $supplierReceipt, array $incomingLines = [], ?int $targetRayonWarehouseId = null)
    {
        app(SupplierProcurementService::class)->syncStockForReceipt($supplierReceipt);
    }

    public function destroy($id, SupplierProcurementService $procurementService)
    {
        $supplierReceipt = SupplierReceipt::find($id);
        if ($supplierReceipt) {
            if ((int) $supplierReceipt->statut >= SupplierReceipt::STATUS_VALIDATED) {
                $procurementService->unvalidateReceipt($supplierReceipt);
            }
            $supplierReceipt->delete();
        }

        return response()->json([
            'statut' => 1,
            'data' => $supplierReceipt,
        ]);
    }
}
