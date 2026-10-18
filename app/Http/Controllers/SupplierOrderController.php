<?php

namespace App\Http\Controllers;

use App\Models\ProductVariationAttribute;
use App\Models\Supplier;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderPva;
use App\Models\SupplierPva;
use App\Models\Warehouse;
use App\Services\SupplierProcurementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SupplierOrderController extends Controller
{
    public function index(Request $request)
    {
        $searchIds = [];
        $requestData = collect($request->query())->toArray();

        if (isset($requestData['suppliers']) && array_filter($requestData['suppliers'], fn ($value) => $value !== null)) {
            foreach ($requestData['suppliers'] as $supplierId) {
                $sup = Supplier::find($supplierId);
                if ($sup) {
                    $searchIds = array_merge($searchIds, $sup->supplierOrders->pluck('id')->unique()->toArray());
                }
            }
            $requestData['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        if (isset($requestData['warehouses']) && array_filter($requestData['warehouses'], fn ($value) => $value !== null)) {
            foreach ($requestData['warehouses'] as $warehouseId) {
                $wh = Warehouse::find($warehouseId);
                if ($wh) {
                    $searchIds = array_merge($searchIds, $wh->supplierOrders->pluck('id')->unique()->toArray());
                }
            }
            $requestData['whereArray'] = ['column' => 'id', 'values' => $searchIds];
        }

        $associated = [];
        $filters = HelperFunctions::filterColumns($requestData, ['id', 'code']);
        $model = 'App\\Models\\SupplierOrder';
        $requestData['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];

        $datas = FilterController::searchs(new Request($requestData), $model, ['id', 'code'], false, $associated);

        $datas = $datas->map(function (SupplierOrder $supplierOrder) {
            $productData = $supplierOrder->only('id', 'code', 'shipping_date', 'created_at', 'statut');
            $productData['user'] = $supplierOrder->accountUser && $supplierOrder->accountUser->user ? [
                'id' => $supplierOrder->accountUser->user->id,
                'firstname' => $supplierOrder->accountUser->user->firstname,
                'lastname' => $supplierOrder->accountUser->user->lastname,
                'images' => $supplierOrder->accountUser->user->images,
            ] : null;

            $productData['time'] = ['statut' => 0, 'data' => '3 Days'];

            $orderLines = $supplierOrder->supplierOrderPvas()
                ->whereNull('supplier_receipt_id')
                ->with([
                    'productVariationAttribute.product.images',
                    'productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                    'orderAllocations.supplierReceipt',
                ])
                ->get();

            $orderedQty = 0;
            $receivedQty = 0;
            $cancelledQty = 0;
            $total = 0;
            $receiptMap = [];

            $productData['productVariations'] = $orderLines->map(function (SupplierOrderPva $supplierOrderPva) use (
                &$orderedQty,
                &$receivedQty,
                &$cancelledQty,
                &$total,
                &$receiptMap
            ) {
                $pva = $supplierOrderPva->productVariationAttribute;
                $product = $pva ? $pva->product : null;
                $firstImage = $product && $product->images ? $product->images->sortByDesc('created_at')->first() : null;
                $imagePath = $firstImage ? ($firstImage->photo_dir . $firstImage->photo) : null;

                $lineQty = max(0, (int) $supplierOrderPva->quantity);
                $lineReceived = min($lineQty, max(0, (int) $supplierOrderPva->received_quantity));
                $lineCancelled = min(max(0, $lineQty - $lineReceived), max(0, (int) $supplierOrderPva->cancelled_quantity));
                $lineRemaining = max(0, $lineQty - $lineReceived - $lineCancelled);

                $orderedQty += $lineQty;
                $receivedQty += $lineReceived;
                $cancelledQty += $lineCancelled;
                $total += $lineQty * (float) $supplierOrderPva->price;

                $allocations = [];
                foreach ($supplierOrderPva->orderAllocations as $alloc) {
                    $rcpt = $alloc->supplierReceipt;
                    if ($rcpt) {
                        $allocations[] = [
                            'receipt_id' => $rcpt->id,
                            'receipt_code' => $rcpt->code,
                            'allocated_quantity' => (int) $alloc->allocated_quantity,
                            'created_at' => $rcpt->created_at,
                        ];
                        if (!isset($receiptMap[$rcpt->id])) {
                            $receiptMap[$rcpt->id] = [
                                'id' => $rcpt->id,
                                'code' => $rcpt->code,
                                'created_at' => $rcpt->created_at,
                                'statut' => $rcpt->statut,
                                'allocated_quantity' => 0,
                            ];
                        }
                        $receiptMap[$rcpt->id]['allocated_quantity'] += (int) $alloc->allocated_quantity;
                    }
                }

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
                    'id' => $pva ? $pva->id : $supplierOrderPva->id,
                    'sop_id' => $supplierOrderPva->id,
                    'product_id' => $product ? $product->id : null,
                    'product' => $product ? $product->reference : '',
                    'product_title' => $product ? $product->title : '',
                    'title' => $product ? $product->title : '',
                    'sku' => $product ? $product->reference : '',
                    'images' => $product && $product->images ? $product->images : [],
                    'image' => $imagePath,
                    'thumbnail_url' => $imagePath,
                    'quantity' => $lineQty,
                    'received_quantity' => $lineReceived,
                    'arrived' => $lineReceived,
                    'cancelled_quantity' => $lineCancelled,
                    'remaining_quantity' => $lineRemaining,
                    'price' => $supplierOrderPva->price,
                    'variations' => $variations,
                    'allocations' => $allocations,
                ];
            });

            $remainingQty = max(0, $orderedQty - $receivedQty - $cancelledQty);

            $productData['quantity'] = $orderedQty;
            $productData['ordered_quantity'] = $orderedQty;
            $productData['received_quantity'] = $receivedQty;
            $productData['cancelled_quantity'] = $cancelledQty;
            $productData['remaining_quantity'] = $remainingQty;
            $productData['reste'] = $remainingQty;
            $productData['progress_percent'] = $orderedQty > 0
                ? (int) round(($receivedQty / $orderedQty) * 100)
                : 0;
            $productData['receipts'] = array_values($receiptMap);
            $productData['supplier'] = $supplierOrder->supplier;
            if ($productData['supplier']) {
                $productData['supplier']['images'] = $supplierOrder->supplier->images ?? [];
            }
            $productData['warehouse'] = $supplierOrder->warehouse;
            $productData['total'] = $total;

            return $productData;
        });

        return HelperFunctions::getPagination($datas, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
    }

    public function generatePdf($id)
    {
        $supplierOrder = SupplierOrder::find($id);
        $orderPvas = SupplierOrderPva::where('supplier_order_id', $id)->whereNull('supplier_receipt_id')->get();
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
        $datas['orderFor'] = $supplierOrder->supplier->title;
        $datas['account'] = $supplierOrder->supplier->account->name;
        $datas['code'] = $supplierOrder->code;
        $datas['countOrders'] = $orderPvas->count();
        $html = view('pdf.supplierOrder', $datas)->render();

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

    public function create(Request $request)
    {
        $requestData = collect($request->query())->toArray();
        $data = [];
        if (isset($requestData['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            $supplierId = $requestData['products']['inactive']['supplier'] ?? 0;
            $supplierPvaMap = SupplierPva::where(['supplier_id' => $supplierId])
                ->pluck('price', 'product_variation_attribute_id')
                ->toArray();
            $pvas = array_keys($supplierPvaMap);
            $productIds = ProductVariationAttribute::whereIn('id', $pvas)->pluck('product_id')->toArray();

            $filters = HelperFunctions::filterColumns($requestData['products']['inactive'], ['id', 'code']);
            $requestData['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $requestData['products']['inactive']['whereArray'] = ['column' => 'id', 'values' => $productIds];
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
                ->map(function ($product) use ($pvas, $supplierPvaMap) {
                    $productData = $product->only('id', 'title', 'reference');
                    $productData['productType'] = $product->productType;
                    $productData['images'] = $product->images ? [$product->images->sortByDesc('created_at')->first()] : [];
                    $productData['productVariations'] = $product->activePvas->map(function ($productVariationAttribute) use ($product, $pvas, $supplierPvaMap) {
                        if ($product->product_type_id == 1 && in_array($productVariationAttribute->id, $pvas)) {
                            $pvaData = [
                                'id' => $productVariationAttribute->id,
                                'price' => $supplierPvaMap[$productVariationAttribute->id] ?? 0,
                            ];
                            $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                                if ($childVariationAttribute->attribute && $childVariationAttribute->attribute->typeAttribute) {
                                    return [
                                        'id' => $childVariationAttribute->id,
                                        'type' => $childVariationAttribute->attribute->typeAttribute->title,
                                        'value' => $childVariationAttribute->attribute->title,
                                    ];
                                }
                                return null;
                            })->filter()->values();
                            return $pvaData;
                        }
                        return null;
                    })->filter()->values();
                    return $productData;
                });
            $data['products']['inactive'] = HelperFunctions::getPagination($products, $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }

    public function store(Request $requests)
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
                function ($attribute, $value, $fail) use ($requests) {
                    $supplier_id = $requests->toArray()[0]['supplier_id'] ?? null;
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
            '*.shipping_date' => 'nullable|date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ]);
        }

        $supplierOrders = collect($requests->except('_method'))->map(function ($request) {
            $request['account_user_id'] = getAccountUser()->id;
            $account_id = getAccountUser()->account_id;
            $request['code'] = DefaultCodeController::getAccountCode('SupplierOrder', $account_id);
            $request['statut'] = isset($request['statut']) ? (int) $request['statut'] : SupplierOrder::STATUS_PENDING;

            $supplierOrder_only = collect($request)->only('code', 'shipping_date', 'warehouse_id', 'supplier_id', 'statut', 'account_user_id');
            $supplierOrder = SupplierOrder::create($supplierOrder_only->all());

            if (isset($request['productVariationAttributes'])) {
                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $pva = SupplierPva::firstOrCreate(
                        [
                            'supplier_id' => $supplierOrder->supplier_id,
                            'product_variation_attribute_id' => $pvaData['id'],
                        ],
                        [
                            'price' => $pvaData['price'] ?? 0,
                            'statut' => 1,
                            'account_id' => $account_id,
                        ]
                    );

                    SupplierOrderPva::create([
                        'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                        'supplier_order_id' => $supplierOrder->id,
                        'supplier_receipt_id' => null,
                        'quantity' => (int) $pvaData['quantity'],
                        'received_quantity' => 0,
                        'cancelled_quantity' => 0,
                        'hors_commande_quantity' => 0,
                        'price' => isset($pvaData['price']) ? $pvaData['price'] : $pva->price,
                        'account_user_id' => getAccountUser()->id,
                        'statut' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return SupplierOrder::with('supplier')->find($supplierOrder->id);
        });

        return response()->json([
            'statut' => 1,
            'data' => $supplierOrders,
        ]);
    }

    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $requestData = collect($request->query())->toArray();
        $data = [];
        $supplierOrder = SupplierOrder::with(['warehouse', 'supplier.warehouses', 'supplier.addresses.city', 'supplier.phones.PhoneTypes'])->find($id);
        if (!$supplierOrder) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist',
            ]);
        }

        if (isset($requestData['supplierOrderInfo'])) {
            $data['supplierOrderInfo']['data'] = $supplierOrder;
            $data['supplierOrderInfo']['data']['principalImage'] = $supplierOrder->supplier ? $supplierOrder->supplier->images : [];
        }

        if (isset($requestData['products']['active'])) {
            $filters = HelperFunctions::filterColumns($requestData['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $orderLines = SupplierOrderPva::where('supplier_order_id', $supplierOrder->id)
                ->whereNull('supplier_receipt_id')
                ->with([
                    'productVariationAttribute.product.images',
                    'productVariationAttribute.product.productType',
                    'productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                ])
                ->get();

            $pvas = [];
            $products = [];

            foreach ($orderLines as $line) {
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

                $pvas[$product->id][] = [
                    'id' => $pva->id,
                    'price' => $line->price,
                    'quantity' => $line->quantity,
                    'received_quantity' => (int) $line->received_quantity,
                    'cancelled_quantity' => (int) $line->cancelled_quantity,
                    'remaining_quantity' => max(0, (int) $line->quantity - (int) $line->received_quantity - (int) $line->cancelled_quantity),
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
            '*.id' => 'required|exists:supplier_orders,id',
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

        $supplierOrders = collect($requests->except('_method'))->map(function ($request) use ($procurementService) {
            $supplierOrder = SupplierOrder::find($request['id']);
            $account_id = getAccountUser()->account_id;

            $updateData = collect($request)->only('shipping_date', 'warehouse_id', 'supplier_id', 'statut')->toArray();
            $supplierOrder->update($updateData);

            if (isset($request['productVariationAttributes'])) {
                $existingOrderLines = SupplierOrderPva::where('supplier_order_id', $supplierOrder->id)
                    ->whereNull('supplier_receipt_id')
                    ->get();

                $incomingPvaIds = collect($request['productVariationAttributes'])->pluck('id')->map(fn ($id) => (int) $id)->toArray();

                // Only delete lines that were removed and have 0 received_quantity
                foreach ($existingOrderLines as $existingLine) {
                    if (!in_array((int) $existingLine->product_variation_attribute_id, $incomingPvaIds, true)) {
                        if ((int) $existingLine->received_quantity === 0) {
                            $existingLine->delete();
                        }
                    }
                }

                foreach ($request['productVariationAttributes'] as $pvaData) {
                    $isExist = SupplierOrderPva::where([
                        'product_variation_attribute_id' => $pvaData['id'],
                        'supplier_order_id' => $supplierOrder->id,
                    ])->whereNull('supplier_receipt_id')->first();

                    if ($isExist) {
                        $newQty = max((int) $isExist->received_quantity, (int) ($pvaData['quantity'] ?? $isExist->quantity));
                        $isExist->update([
                            'quantity' => $newQty,
                            'price' => isset($pvaData['price']) ? $pvaData['price'] : $isExist->price,
                        ]);
                    } else {
                        $pva = SupplierPva::firstOrCreate(
                            [
                                'supplier_id' => $supplierOrder->supplier_id,
                                'product_variation_attribute_id' => $pvaData['id'],
                            ],
                            [
                                'price' => $pvaData['price'] ?? 0,
                                'statut' => 1,
                                'account_id' => $account_id,
                            ]
                        );
                        SupplierOrderPva::create([
                            'product_variation_attribute_id' => $pva->product_variation_attribute_id,
                            'supplier_order_id' => $supplierOrder->id,
                            'supplier_receipt_id' => null,
                            'quantity' => (int) $pvaData['quantity'],
                            'received_quantity' => 0,
                            'cancelled_quantity' => 0,
                            'hors_commande_quantity' => 0,
                            'price' => isset($pvaData['price']) ? $pvaData['price'] : $pva->price,
                            'account_user_id' => getAccountUser()->id,
                            'statut' => 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }

            $procurementService->recalculateOrderStatus($supplierOrder->fresh());

            return SupplierOrder::with('supplier')->find($supplierOrder->id);
        });

        return response()->json([
            'statut' => 1,
            'data' => $supplierOrders,
        ]);
    }

    /**
     * Force-close remaining quantities on a SupplierOrder ("Clôturer le reste").
     */
    public function closeRemaining(Request $request, $id, SupplierProcurementService $procurementService)
    {
        $supplierOrder = SupplierOrder::find($id);
        if (!$supplierOrder) {
            return response()->json([
                'statut' => 0,
                'message' => 'Commande fournisseur introuvable',
            ], 404);
        }

        $pvaId = $request->input('pva_id') ? (int) $request->input('pva_id') : null;
        $updated = $procurementService->closeOrderRemaining($supplierOrder, $pvaId);

        return response()->json([
            'statut' => 1,
            'data' => $updated,
            'message' => 'Reliquat clôturé avec succès',
        ]);
    }

    public function destroy($id)
    {
        $supplierOrder = SupplierOrder::find($id);
        if ($supplierOrder) {
            $supplierOrder->delete();
        }
        return response()->json([
            'statut' => 1,
            'data' => $supplierOrder,
        ]);
    }
}
