<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\CustomerIdentityService;
use App\Support\Orders\ReturnRules;
use App\Models\Address;
use App\Models\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Apply a COD segment filter to a Customer query.
     */
    public static function applySegmentFilter($query, string $segment): void
    {
        switch ($segment) {
            case 'repeat':
            case 'multiple_orders':
                $query->whereHas('orders', function ($q) {
                    $q->where('type', 'sale')->whereIn('order_status_id', [7, 10]);
                }, '>=', 2);
                break;

            case 'vip':
            case 'loyal':
                $query->where('customers.is_blacklisted', false)
                    ->where(function ($q) {
                        $q->whereHas('orders', function ($sub) {
                            $sub->where('type', 'sale')->whereIn('order_status_id', [7, 10]);
                        }, '>=', 3)
                        ->orWhereRaw('(
                            SELECT COALESCE(SUM(order_pva.price * order_pva.quantity), 0)
                            FROM orders
                            JOIN order_pva ON orders.id = order_pva.order_id
                            WHERE orders.customer_id = customers.id
                              AND orders.type = "sale"
                              AND orders.order_status_id IN (7, 10)
                              AND orders.deleted_at IS NULL
                              AND order_pva.deleted_at IS NULL
                              AND order_pva.order_status_id NOT IN (2, 3)
                        ) >= 2500');
                    });
                break;

            case 'dormant':
                $cutoff = now()->subDays(45);
                $query->where('customers.is_blacklisted', false)
                    ->whereHas('orders', function ($q) {
                        $q->where('type', 'sale')->whereIn('order_status_id', [7, 10]);
                    }, '>=', 1)
                    ->whereDoesntHave('orders', function ($q) use ($cutoff) {
                        $q->where('type', 'sale')->where('created_at', '>=', $cutoff);
                    });
                break;

            case 'risky':
                $query->where(function ($q) {
                    $q->whereHas('orders', function ($sub) {
                        $sub->where('type', 'sale')->where('order_status_id', 11);
                    }, '>=', 2)
                    ->orWhere(function ($sub) {
                        $sub->whereHas('orders', function ($r) {
                            $r->where('type', 'sale')->where('order_status_id', 11);
                        }, '>=', 1)
                        ->whereDoesntHave('orders', function ($d) {
                            $d->where('type', 'sale')->whereIn('order_status_id', [7, 10]);
                        });
                    });
                });
                break;

            case 'blacklisted':
                $query->where('customers.is_blacklisted', true);
                break;
        }
    }

    /**
     * Normalize incoming customer payload keys from various frontend forms.
     */
    public static function normalizeCustomerItem(array $item): array
    {
        if (!isset($item['note']) && isset($item['notes'])) {
            $item['note'] = $item['notes'];
        }

        if (!isset($item['customer_type_id']) && isset($item['customer_type'])) {
            $ct = $item['customer_type'];
            if (is_numeric($ct)) {
                $item['customer_type_id'] = (int) $ct;
            } elseif (is_string($ct)) {
                $item['customer_type_id'] = strtolower($ct) === 'company' ? 2 : 1;
            } elseif (is_array($ct) && isset($ct['id'])) {
                $item['customer_type_id'] = (int) $ct['id'];
            }
        }

        if (isset($item['phones']) && is_array($item['phones'])) {
            $normalizedPhones = [];
            foreach ($item['phones'] as $phoneData) {
                if (!is_array($phoneData)) {
                    continue;
                }
                $rawTitle = $phoneData['title'] ?? $phoneData['phone'] ?? '';
                $normTitle = CustomerIdentityService::normalizePhone((string) $rawTitle);
                if ($normTitle === '') {
                    continue;
                }
                $phoneData['title'] = $normTitle;
                $normalizedPhones[] = $phoneData;
            }
            $item['phones'] = $normalizedPhones;
        }

        if (isset($item['addresses']) && is_array($item['addresses'])) {
            $normalizedAddresses = [];
            foreach ($item['addresses'] as $addressData) {
                if (!is_array($addressData)) {
                    continue;
                }
                $title = trim((string) ($addressData['title'] ?? ''));
                $detail = trim((string) ($addressData['address'] ?? ''));
                if ($detail !== '' && $title !== '' && $detail !== $title && !str_contains($title, $detail)) {
                    $addressData['title'] = $title . ' - ' . $detail;
                } elseif ($title === '' && $detail !== '') {
                    $addressData['title'] = $detail;
                }
                if (!empty($addressData['title'])) {
                    $normalizedAddresses[] = $addressData;
                }
            }
            $item['addresses'] = $normalizedAddresses;
        }

        return $item;
    }

    /**
     * Display a paginated and searchable list of customers.
     */
    public function index(Request $request)
    {
        $perPage = max(1, min(200, (int) $request->input('pagination.per_page', 15)));
        $currentPage = max(1, (int) $request->input('pagination.page', $request->input('pagination.current_page', 1)));

        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($data[0]['account_id'] ?? null)
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        if (!$accountId) {
            return response()->json(['statut' => 0, 'data' => 'Account ID not found.'], 422);
        }
        $query = Customer::query()->where('customers.account_id', $accountId);

        // Handle search (name, code, note, or raw/normalized phone)
        if ($request->filled('search')) {
            $searchTerm = trim((string) $request->search);
            $normalizedPhoneSearch = CustomerIdentityService::normalizePhone($searchTerm);

            $query->where(function ($q) use ($searchTerm, $normalizedPhoneSearch) {
                $q->where('customers.name', 'like', "%{$searchTerm}%")
                    ->orWhere('customers.code', 'like', "%{$searchTerm}%")
                    ->orWhere('customers.note', 'like', "%{$searchTerm}%")
                    ->orWhereHas('phones', function ($phoneQuery) use ($searchTerm, $normalizedPhoneSearch) {
                        $phoneQuery->where('title', 'like', "%{$searchTerm}%");
                        if ($normalizedPhoneSearch !== '' && $normalizedPhoneSearch !== $searchTerm) {
                            $phoneQuery->orWhere('title', 'like', "%{$normalizedPhoneSearch}%");
                        }
                    });
            });
        }

        // Handle segment filter or legacy flags
        $segment = (string) $request->input('segment', '');
        if ($segment !== '' && $segment !== 'all') {
            self::applySegmentFilter($query, $segment);
        } else {
            if ($request->has('is_blacklisted') && $request->input('is_blacklisted') !== null && $request->input('is_blacklisted') !== '') {
                $query->where('customers.is_blacklisted', $request->input('is_blacklisted') == 1);
            }
            if ($request->input('has_multiple_orders') == 1) {
                self::applySegmentFilter($query, 'repeat');
            }
            if ($request->input('is_loyal') == 1) {
                self::applySegmentFilter($query, 'vip');
            }
        }

        // Handle City filter
        if ($request->filled('city_id')) {
            $cityId = (int) $request->input('city_id');
            if ($cityId > 0) {
                $query->whereHas('addresses', function ($q) use ($cityId) {
                    $q->where('addresses.city_id', $cityId);
                });
            }
        }

        // Handle Product Bought filter
        if ($request->filled('product_id')) {
            $productId = (int) $request->input('product_id');
            if ($productId > 0) {
                $query->whereHas('orders', function ($oq) use ($productId) {
                    $oq->where('orders.type', 'sale')
                        ->whereHas('orderPvas.productVariationAttribute', function ($pq) use ($productId) {
                            $pq->where('product_id', $productId);
                        });
                });
            }
        }

        // Eager load relationships and order by newest or latest activity
        $query->with(['phones', 'addresses.city', 'customerType', 'images'])
            ->with([
                'latestOrder' => function ($q) {
                    $q->where('type', 'sale');
                }
            ])
            ->orderByDesc('customers.id');

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);
        $customerIds = $paginator->getCollection()->pluck('id')->all();
        $metricsMap = CustomerIdentityService::batchCustomerMetrics($customerIds);

        $formattedData = $paginator->getCollection()->map(function ($customer) use ($metricsMap) {
            $primaryAddress = $customer->addresses->first();
            $m = $metricsMap[$customer->id] ?? [
                'delivered_count' => 0,
                'returned_count' => 0,
                'pending_count' => 0,
                'total_orders' => 0,
                'lifetime_value' => 0.0,
                'average_order_value' => 0.0,
                'delivery_rate' => null,
                'return_rate' => 0.0,
                'tier' => 'Regular',
                'trust_level' => 'new',
            ];

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'note' => $customer->note,
                'notes' => $customer->note,
                'is_blacklisted' => (bool) $customer->is_blacklisted,
                'tier' => $m['tier'],
                'trust_level' => $m['trust_level'],
                'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
                'primary_phone' => $customer->phones->first() ? $customer->phones->first()->title : null,
                'primary_address' => $primaryAddress ? ($primaryAddress->title . ($primaryAddress->city ? ', ' . $primaryAddress->city->title : '')) : null,
                'city' => $primaryAddress && $primaryAddress->city ? $primaryAddress->city->title : null,
                'city_id' => $primaryAddress ? $primaryAddress->city_id : null,
                'images' => $customer->images,
                'orders_count' => $m['total_orders'],
                'delivered_count' => $m['delivered_count'],
                'returned_count' => $m['returned_count'],
                'pending_count' => $m['pending_count'],
                'delivery_rate' => $m['delivery_rate'],
                'return_rate' => $m['return_rate'],
                'lifetime_value' => $m['lifetime_value'],
                'average_order_value' => $m['average_order_value'],
                'last_order_date' => $customer->latestOrder ? $customer->latestOrder->created_at->toIso8601String() : null,
                'created_at' => $customer->created_at ? $customer->created_at->toIso8601String() : null,
                'phones' => $customer->phones->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'phone' => $p->title])->values(),
                'addresses' => $customer->addresses->map(fn ($a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'city_id' => $a->city_id,
                    'city' => $a->city ? $a->city->only('id', 'title') : null,
                ])->values(),
            ];
        });

        return response()->json([
            'statut' => 1,
            'data' => $formattedData,
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'total' => $paginator->total(),
        ]);
    }

    /**
     * Store a newly created customer in storage (or reuse/enrich existing customer if phone already exists).
     */
    public static function store(Request $request, $local = 0)
    {
        $data = $request->except('_method');
        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

        $data = array_values(array_map([self::class, 'normalizeCustomerItem'], $data));

        $validator = Validator::make($data, [
            '*.name' => 'required|string|max:255',
            '*.email' => 'nullable|email|max:255',
            '*.customer_type_id' => 'nullable|exists:customer_types,id',
            '*.note' => 'nullable|string',

            '*.phones' => 'nullable|array',
            '*.phones.*.title' => 'required|string',
            '*.phones.*.phone_type_id' => 'nullable|exists:phone_types,id',

            '*.addresses' => 'nullable|array',
            '*.addresses.*.title' => 'required|string',
            '*.addresses.*.city_id' => 'nullable|exists:cities,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($data[0]['account_id'] ?? null)
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        if (!$accountId) {
            return response()->json(['statut' => 0, 'data' => 'Account ID not found.'], 422);
        }

        $customers = DB::transaction(function () use ($data, $accountId) {
            $createdCustomers = [];
            foreach ($data as $item) {
                $phoneTitles = collect($item['phones'] ?? [])->pluck('title')->filter()->all();
                $existingCustomer = null;

                if (!empty($phoneTitles)) {
                    $existingPhone = Phone::with('customers')
                        ->where('account_id', $accountId)
                        ->whereIn('title', $phoneTitles)
                        ->whereHas('customers')
                        ->orderBy('created_at', 'desc')
                        ->first();
                    $existingCustomer = $existingPhone?->customers?->first();
                }

                if ($existingCustomer) {
                    $bestName = CustomerIdentityService::chooseBestName($existingCustomer->name, $item['name'] ?? null);
                    $existingCustomer->update([
                        'name' => $bestName,
                        'customer_type_id' => $item['customer_type_id'] ?? $existingCustomer->customer_type_id ?? 1,
                        'note' => !empty($item['note']) ? $item['note'] : $existingCustomer->note,
                    ]);
                    $customer = $existingCustomer;
                } else {
                    $customerData = \Illuminate\Support\Arr::only($item, ['name', 'email', 'customer_type_id', 'note']);
                    $customerData['customer_type_id'] = $customerData['customer_type_id'] ?? 1;
                    $customerData['account_id'] = $accountId;
                    $customerData['code'] = DefaultCodeController::getAccountCode('Customer', $accountId);
                    $customer = Customer::create($customerData);
                }

                if (isset($item['phones'])) {
                    foreach ($item['phones'] as $phoneData) {
                        $normPhone = CustomerIdentityService::normalizePhone($phoneData['title'] ?? '');
                        if ($normPhone === '') {
                            continue;
                        }
                        $phoneModel = Phone::where('account_id', $accountId)->where('title', $normPhone)->first();
                        if (!$phoneModel) {
                            $phoneModel = Phone::create([
                                'title' => $normPhone,
                                'account_id' => $accountId,
                            ]);
                        }
                        $customer->phones()->syncWithoutDetaching([$phoneModel->id]);
                    }
                }

                if (isset($item['addresses'])) {
                    foreach ($item['addresses'] as $addressData) {
                        $title = trim($addressData['title'] ?? '');
                        $cityId = $addressData['city_id'] ?? null;
                        if ($title === '') {
                            continue;
                        }
                        $existingAddress = $customer->addresses()
                            ->where('title', $title)
                            ->when($cityId !== null, fn ($q) => $q->where('city_id', $cityId))
                            ->first();
                        if (!$existingAddress) {
                            $customer->addresses()->create([
                                'title' => $title,
                                'city_id' => $cityId,
                                'account_id' => $accountId,
                            ]);
                        }
                    }
                }

                $createdCustomers[] = $customer->load('phones', 'addresses.city');
            }
            return collect($createdCustomers);
        });

        if ($local == 1) {
            return $customers;
        }

        return response()->json([
            'statut' => 1,
            'data' => $customers,
            'message' => 'Customer created successfully'
        ], 201);
    }

    /**
     * Display the specified customer with their complete history.
     */
    public function show($id)
    {
        $customer = Customer::where('account_id', getAccountUser()->account_id)
            ->with(['phones', 'addresses.city', 'customerType', 'images', 'tags'])
            ->find($id);

        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $metricsMap = CustomerIdentityService::batchCustomerMetrics([$customer->id]);
        $m = $metricsMap[$customer->id] ?? [
            'delivered_count' => 0,
            'returned_count' => 0,
            'pending_count' => 0,
            'total_orders' => 0,
            'lifetime_value' => 0.0,
            'average_order_value' => 0.0,
            'delivery_rate' => null,
            'return_rate' => 0.0,
            'tier' => 'Regular',
            'trust_level' => 'new',
        ];

        // Manually paginate the orders to format them
        $ordersPaginator = $customer->orders()
            ->with([
                'orderStatus',
                'brandSource.brand',
                'brandSource.source',
                'orderComments.comment',
                'orderComments.accountUser',
                'orderPvas.productVariationAttribute.product.images',
                'orderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // pieces of the page's sales already on a return: one query for the page
        $returned = ReturnRules::returnedQuantities($ordersPaginator->getCollection()
            ->filter(fn ($order) => ReturnRules::isReturnable($order))
            ->flatMap(fn ($order) => $order->orderPvas->pluck('id'))
            ->all());

        $formattedOrders = $ordersPaginator->getCollection()->map(function ($order) use ($returned) {
            $orderTotal = 0;
            $returnable = ReturnRules::isReturnable($order);
            $inactive = fn ($orderPva) => in_array((int) $orderPva->order_status_id, ReturnRules::INACTIVE_LINE_STATUSES, true);
            // the lines of the order as the order list shows them (an abandoned order keeps its removed lines)
            $lines = (int) $order->order_status_id === 2 ? $order->orderPvas->filter($inactive) : $order->orderPvas->reject($inactive);
            $unitValues = ReturnRules::unitValuesOf($order->orderPvas->reject($inactive), (float) $order->discount);

            $products = $lines->map(function ($orderPva) use (&$orderTotal, $returnable, $returned, $unitValues) {
                $orderTotal += $orderPva->price * $orderPva->quantity;
                $alreadyReturned = $returned[$orderPva->id] ?? 0;
                $pva = $orderPva->productVariationAttribute;

                if (!$pva)
                    return null;

                $attributesText = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                    return $child->attribute->code;
                })->toArray();

                return [
                    'order_pva_id' => $orderPva->id,
                    'product_id' => $pva->product->id,
                    'product' => $pva->product->title . " " . implode('-', $attributesText),
                    'reference' => $pva->product->reference,
                    'quantity' => $orderPva->quantity,
                    'price' => $orderPva->price,
                    'returned_quantity' => $alreadyReturned,
                    'returnable_quantity' => $returnable ? max(0, (int) $orderPva->quantity - $alreadyReturned) : 0,
                    'refund_unit_price' => $unitValues[$orderPva->id] ?? (float) $orderPva->price,
                    'images' => $pva->product->images,
                    'attributes' => $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                        return [
                            "id" => $child->attribute->id,
                            "title" => $child->attribute->title,
                            "typeAttribute" => $child->attribute->typeAttribute ? $child->attribute->typeAttribute->title : null,
                        ];
                    }),
                ];
            })->filter()->values();

            return [
                'id' => $order->id,
                'code' => $order->code,
                'order_id' => $order->order_id,
                'type' => $order->type,
                'status' => $order->orderStatus ? $order->orderStatus->title : null,
                'status_id' => (int) $order->order_status_id,
                // what the customer pays, as on the order list
                'total' => max(0, $orderTotal + (float) $order->carrier_price - (float) $order->discount),
                // a sale the customer has with pieces that can still come back
                'returnable' => $products->sum('returnable_quantity') > 0,
                'date' => $order->created_at->toIso8601String(),
                'products' => $products,
                'brand' => $order->brandSource && $order->brandSource->brand ? $order->brandSource->brand->title : null,
                'source' => $order->brandSource && $order->brandSource->source ? $order->brandSource->source->title : null,
                'order_comments' => $order->orderComments->map(function ($oc) {
                    return [
                        'id' => $oc->id,
                        'title' => $oc->title,
                        'comment' => $oc->comment ? $oc->comment->title : null,
                        'user' => $oc->accountUser ? ($oc->accountUser->first_name . ' ' . $oc->accountUser->last_name) : null,
                        'date' => $oc->created_at->toIso8601String(),
                    ];
                })->values()->toArray(),
            ];
        });

        $customerData = [
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'note' => $customer->note,
            'notes' => $customer->note,
            'primary_phone' => $customer->phones->first() ? $customer->phones->first()->title : null,
            'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
            'phones' => $customer->phones->map(fn ($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'phone' => $p->title,
            ])->values(),
            'addresses' => $customer->addresses,
            'images' => $customer->images,
            'wallet_balance' => (float) $customer->wallet_balance,
            'discount_percent' => (float) $customer->discount_percent,
            'is_blacklisted' => (bool) $customer->is_blacklisted,
            'tags' => $customer->tags,
            'orders_count' => $m['total_orders'],
            'delivered_count' => $m['delivered_count'],
            'returned_count' => $m['returned_count'],
            'pending_count' => $m['pending_count'],
            'delivery_rate' => $m['delivery_rate'],
            'lifetime_value' => $m['lifetime_value'],
            'average_order_value' => $m['average_order_value'],
            'return_rate' => $m['return_rate'],
            'tier' => $m['tier'],
            'trust_level' => $m['trust_level'],
            'orders' => [
                'data' => $formattedOrders,
                'per_page' => $ordersPaginator->perPage(),
                'current_page' => $ordersPaginator->currentPage(),
                'total' => $ordersPaginator->total(),
            ]
        ];

        return response()->json(['statut' => 1, 'data' => $customerData]);
    }

    /**
     * Update the specified customer in storage.
     */
    public static function update(Request $request, $id, $isOrder = 0)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($request->input('0.account_id'))
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        $customer = $accountId ? Customer::where('account_id', $accountId)->find($id) : Customer::find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $data = $request->except('_method');
        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

        $data = array_values(array_map([self::class, 'normalizeCustomerItem'], $data));

        $validator = Validator::make($data, [
            '*.name' => 'sometimes|required|string|max:255',
            '*.email' => 'sometimes|nullable|email|max:255',
            '*.customer_type_id' => 'sometimes|nullable|exists:customer_types,id',
            '*.note' => 'sometimes|nullable|string',

            '*.phones' => 'sometimes|nullable|array',
            '*.phones.*.id' => 'nullable|exists:phones,id',
            '*.phones.*.title' => 'required|string',

            '*.addresses' => 'sometimes|nullable|array',
            '*.addresses.*.id' => 'nullable|exists:addresses,id',
            '*.addresses.*.title' => 'required|string',
            '*.addresses.*.city_id' => 'nullable|exists:cities,id',
        ]);

        if ($validator->fails()) {
            if ($isOrder)
                return response()->json(['Validation Error', $validator->errors()]);
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $afraService = app(\App\Services\AfraShippingService::class);
        $afraOrders = $isOrder ? collect() : \App\Models\Order::where('account_id', getAccountUser()->account_id)
            ->where('customer_id', $id)->whereNotNull('shipping_code')
            ->whereHas('pickup', fn ($q) => $q->where('carrier_id', \App\Services\AfraShippingClient::carrierId()))->get();
        $before = $afraOrders->mapWithKeys(fn ($order) => [$order->id => $afraService->fingerprint($order)]);

        DB::transaction(function () use ($data, $customer, $isOrder) {
            $item = $data[0];
            $updateFields = \Illuminate\Support\Arr::only($item, ['name', 'email', 'customer_type_id', 'note']);

            // When called from order creation/update, never overwrite a real customer name with a placeholder
            if ($isOrder && isset($updateFields['name'])) {
                $updateFields['name'] = CustomerIdentityService::chooseBestName($customer->name, $updateFields['name']);
            }

            $customer->update($updateFields);

            // Sync Phones
            if (isset($item['phones'])) {
                $phoneIds = [];
                foreach ($item['phones'] as $phoneData) {
                    $normPhone = CustomerIdentityService::normalizePhone($phoneData['title'] ?? '');
                    if ($normPhone === '') {
                        continue;
                    }

                    $phoneTypes = isset($phoneData['phoneTypes']) ? collect($phoneData['phoneTypes'])
                        ->map(fn ($pt) => is_array($pt) ? ($pt['id'] ?? null) : (is_numeric($pt) ? (int) $pt : null))
                        ->filter()->values()->all() : null;

                    if (isset($phoneData['id'])) {
                        $phone = Phone::find($phoneData['id']);
                        if ($phone) {
                            $phone->update(['title' => $normPhone]);
                            if ($phoneTypes !== null) {
                                $phone->phoneTypes()->sync($phoneTypes);
                            }
                            $phoneIds[] = $phone->id;
                        }
                    } else {
                        $existingPhone = $customer->phones()
                            ->where('title', $normPhone)
                            ->first();

                        if ($existingPhone) {
                            if ($phoneTypes !== null) {
                                $existingPhone->phoneTypes()->sync($phoneTypes);
                            }
                            $phoneIds[] = $existingPhone->id;
                        } else {
                            $accountPhone = Phone::where('account_id', $customer->account_id)
                                ->where('title', $normPhone)
                                ->first();

                            if ($accountPhone) {
                                $customer->phones()->syncWithoutDetaching([$accountPhone->id]);
                                if ($phoneTypes !== null) {
                                    $accountPhone->phoneTypes()->sync($phoneTypes);
                                }
                                $phoneIds[] = $accountPhone->id;
                            } else {
                                $newPhone = $customer->phones()->create([
                                    'title' => $normPhone,
                                    'account_id' => $customer->account_id,
                                ]);
                                if ($phoneTypes !== null) {
                                    $newPhone->phoneTypes()->sync($phoneTypes);
                                }
                                $phoneIds[] = $newPhone->id;
                            }
                        }
                    }
                }
                // Remove any phones not in the request only when directly editing customer, never when placing an order
                if (!$isOrder && !empty($phoneIds)) {
                    $toDetachPhones = $customer->phones()->whereNotIn('phones.id', $phoneIds)->pluck('phones.id')->all();
                    if (!empty($toDetachPhones)) {
                        $customer->phones()->detach($toDetachPhones);
                    }
                }
            }

            // Sync Addresses
            if (isset($item['addresses'])) {
                $addressIds = [];
                foreach ($item['addresses'] as $addressData) {
                    $title = trim($addressData['title'] ?? '');
                    $cityId = $addressData['city_id'] ?? null;
                    if ($title === '') {
                        continue;
                    }

                    if (isset($addressData['id'])) {
                        $address = Address::find($addressData['id']);
                        if ($address) {
                            $address->update([
                                'title' => $title,
                                'city_id' => $cityId ?? $address->city_id,
                            ]);
                            $addressIds[] = $address->id;
                        }
                    } else {
                        $existingAddress = $customer->addresses()
                            ->where('title', $title)
                            ->when($cityId !== null, fn ($query) => $query->where('city_id', $cityId))
                            ->first();

                        if ($existingAddress) {
                            // Touch pivot so the used address floats to the top as most recent
                            DB::table('addressables')
                                ->where('address_id', $existingAddress->id)
                                ->where('addressable_id', $customer->id)
                                ->where('addressable_type', Customer::class)
                                ->update(['updated_at' => now(), 'created_at' => now()]);
                            $addressIds[] = $existingAddress->id;
                        } else {
                            $newAddress = $customer->addresses()->create([
                                'title' => $title,
                                'city_id' => $cityId,
                                'account_id' => $customer->account_id,
                            ]);
                            $addressIds[] = $newAddress->id;
                        }
                    }
                }
                // Remove addresses not in the request only when directly editing customer, never when placing an order
                if (!$isOrder && !empty($addressIds)) {
                    $toDetachAddresses = $customer->addresses()->whereNotIn('addresses.id', $addressIds)->pluck('addresses.id')->all();
                    if (!empty($toDetachAddresses)) {
                        $customer->addresses()->detach($toDetachAddresses);
                    }
                }
            }
        });

        $updatedCustomer = $customer->fresh(['phones', 'addresses.city']);

        if ($isOrder == 1)
            return collect([['phones' => $updatedCustomer->phones, 'addresses' => $updatedCustomer->addresses, 'customer' => $updatedCustomer]]);

        $afraSync = [];
        foreach ($afraOrders as $order) {
            $fresh = $order->fresh();
            if ($before->get($order->id) !== $afraService->fingerprint($fresh)) {
                $afraSync[$order->id] = $afraService->update($fresh, getAccountUser()->id);
            }
        }

        return response()->json([
            'statut' => 1,
            'data' => $updatedCustomer,
            'message' => 'Customer updated successfully',
            'afra_sync' => $afraSync,
        ]);
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy($id)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($request->input('0.account_id'))
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        $customer = $accountId ? Customer::where('account_id', $accountId)->find($id) : Customer::find($id);

        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $customer->delete();

        return response()->json(['statut' => 1, 'message' => 'Customer deleted successfully']);
    }

    public function logCall(Request $request, $id)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($request->input('0.account_id'))
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        $customer = $accountId ? Customer::where('account_id', $accountId)->find($id) : Customer::find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $request->validate([
            'outcome' => 'required|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $call = $customer->calls()->create([
            'outcome' => $request->outcome,
            'notes' => $request->notes,
        ]);

        return response()->json(['statut' => 1, 'message' => 'Call logged successfully', 'data' => $call]);
    }

    public function toggleBlacklist(Request $request, $id)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($request->input('0.account_id'))
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        $customer = $accountId ? Customer::where('account_id', $accountId)->find($id) : Customer::find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $customer->is_blacklisted = !$customer->is_blacklisted;

        if ($customer->is_blacklisted && $request->filled('reason')) {
            $reason = trim((string) $request->input('reason'));
            $existingNote = trim((string) $customer->note);
            if ($reason !== '' && !str_contains($existingNote, $reason)) {
                $customer->note = $existingNote !== '' ? ($existingNote . ' | Blacklist: ' . $reason) : ('Blacklist: ' . $reason);
            }
        }

        $customer->save();

        return response()->json([
            'statut' => 1,
            'message' => 'Blacklist status toggled',
            'is_blacklisted' => $customer->is_blacklisted,
            'note' => $customer->note,
        ]);
    }

    public function timeline($id)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($request->input('0.account_id'))
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        $customer = $accountId ? Customer::where('account_id', $accountId)->find($id) : Customer::find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $timeline = collect();

        // Customer created
        $timeline->push([
            'type' => 'customer_created',
            'date' => $customer->created_at,
            'description' => 'Customer profile was created.',
            'data' => null
        ]);

        // Orders & Returns
        $orders = $customer->orders()->with(['orderStatus', 'parentOrder'])->get();
        foreach ($orders as $order) {
            $eventType = 'order_placed';
            $description = 'Order #' . $order->code . ' was placed.';

            if ($order->type === 'return') {
                $eventType = 'return_created';
                $description = 'Return Order #' . $order->code . ' was created.';
            } elseif ($order->type === 'sale' && $order->order_id) {
                // Check parent order type to distinguish between exchange and swap
                if ($order->parentOrder && $order->parentOrder->type === 'return') {
                    $eventType = 'exchange_created';
                    $description = 'Exchange Order #' . $order->code . ' was created from Return #' . $order->parentOrder->code . '.';
                } else if ($order->parentOrder && $order->parentOrder->type === 'sale') {
                    $eventType = 'delivery_swapped';
                    $description = 'Order #' . $order->code . ' took over delivery from Order #' . $order->parentOrder->code . '.';
                }
            }

            $timeline->push([
                'type' => $eventType,
                'date' => $order->created_at,
                'description' => $description,
                'data' => [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'status' => $order->orderStatus ? $order->orderStatus->title : null,
                ]
            ]);

            // Assuming order_status_id = 11 is Returned
            if ($order->order_status_id == 11) {
                // Approximate return date by updated_at, as we don't have a specific return date field
                $timeline->push([
                    'type' => 'return_initiated',
                    'date' => $order->updated_at,
                    'description' => 'Order #' . $order->code . ' was returned.',
                    'data' => [
                        'order_id' => $order->id,
                        'code' => $order->code
                    ]
                ]);
            }
        }

        // CRM Calls
        $calls = $customer->calls()->get();
        foreach ($calls as $call) {
            $timeline->push([
                'type' => 'call_logged',
                'date' => $call->created_at,
                'description' => 'Customer Call: ' . $call->outcome,
                'data' => [
                    'outcome' => $call->outcome,
                    'notes' => $call->notes
                ]
            ]);
        }

        // Order Calls
        $orderCalls = \App\Models\OrderCall::whereHas('order', function ($query) use ($customer) {
            $query->where('customer_id', $customer->id);
        })->get();

        foreach ($orderCalls as $orderCall) {
            $timeline->push([
                'type' => 'order_call_logged',
                'date' => $orderCall->called_at ?? $orderCall->created_at,
                'description' => 'Order Call (' . ($orderCall->result ?? 'No result') . ')',
                'data' => [
                    'order_id' => $orderCall->order_id,
                    'employee_id' => $orderCall->employee_id,
                    'call_number' => $orderCall->call_number,
                    'result' => $orderCall->result,
                    'duration' => $orderCall->call_duration,
                    'notes' => $orderCall->note
                ]
            ]);
        }

        // Sort by date descending
        $sortedTimeline = $timeline->filter(function ($item) {
            return !is_null($item['date']);
        })->sortByDesc('date')->values()->map(function ($item) {
            $item['date'] = $item['date']->toIso8601String();
            return $item;
        });

        return response()->json(['statut' => 1, 'data' => $sortedTimeline]);
    }

    public function merge(Request $request)
    {
        $request->validate([
            'primary_id' => 'required|exists:customers,id',
            'secondary_id' => 'required|exists:customers,id|different:primary_id',
        ]);

        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($data[0]['account_id'] ?? null)
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        if (!$accountId) {
            return response()->json(['statut' => 0, 'data' => 'Account ID not found.'], 422);
        }

        $primary = Customer::where('account_id', $accountId)->find($request->primary_id);
        $secondary = Customer::where('account_id', $accountId)->find($request->secondary_id);

        if (!$primary || !$secondary) {
            return response()->json(['statut' => 0, 'message' => 'One or both customers not found'], 404);
        }

        DB::beginTransaction();

        try {
            CustomerIdentityService::mergeCustomerInto($primary, $secondary);
            DB::commit();

            return response()->json([
                'statut' => 1,
                'message' => 'Customers merged successfully. Secondary customer has been archived.'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['statut' => 0, 'message' => 'Merge failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Normalize all phones and auto-merge duplicate customers sharing the same phone number.
     */
    public function deduplicate()
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($data[0]['account_id'] ?? null)
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        if (!$accountId) {
            return response()->json(['statut' => 0, 'data' => 'Account ID not found.'], 422);
        }
        $stats = CustomerIdentityService::deduplicateAccount($accountId);

        return response()->json([
            'statut' => 1,
            'data' => $stats,
            'message' => 'Customer deduplication completed successfully.',
        ]);
    }

    /**
     * Get global count of customers in each COD segment.
     */
    public function counts(Request $request)
    {
        $accountId = getAccountUser()?->account_id
            ?? $request->input('account_id')
            ?? ($data[0]['account_id'] ?? null)
            ?? (class_exists(\App\Support\AccountContext::class) ? \App\Support\AccountContext::id() : null);

        if (!$accountId) {
            return response()->json(['statut' => 0, 'data' => 'Account ID not found.'], 422);
        }

        $countSegment = function (string $segment) use ($accountId): int {
            $q = Customer::where('customers.account_id', $accountId);
            if ($segment !== 'all') {
                self::applySegmentFilter($q, $segment);
            }
            return $q->count();
        };

        $all = $countSegment('all');
        $repeat = $countSegment('repeat');
        $vip = $countSegment('vip');
        $dormant = $countSegment('dormant');
        $risky = $countSegment('risky');
        $blacklisted = $countSegment('blacklisted');

        return response()->json([
            'all' => $all,
            'repeat' => $repeat,
            'multiple_orders' => $repeat,
            'vip' => $vip,
            'loyal' => $vip,
            'dormant' => $dormant,
            'risky' => $risky,
            'blacklisted' => $blacklisted,
        ]);
    }
}
