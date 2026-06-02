<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Address;
use App\Models\Phone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    /**
     * Display a paginated and searchable list of customers.
     */
    public function index(Request $request)
    {
        $perPage = $request->input('pagination.per_page', 15);
        $currentPage = $request->input('pagination.current_page', 1);

        $query = Customer::query()->where('customers.account_id', getAccountUser()->account_id);

        // Handle search
        if ($request->has('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', "%{$searchTerm}%")
                    ->orWhereHas('phones', function ($phoneQuery) use ($searchTerm) {
                        $phoneQuery->where('title', 'like', "%{$searchTerm}%");
                    });
            });
        }
        
        // Eager load relationships and calculate aggregates efficiently
        $query->with(['phones', 'addresses.city', 'customerType', 'images'])
              ->withCount(['orders' => function ($query) {
                  $query->where('type', 'sale');
              }])
              ->with(['latestOrder' => function ($query) {
                  $query->where('type', 'sale');
              }])
              ->select('customers.*')
              ->selectSub(
                  DB::table('orders')
                      ->join('order_pva', 'orders.id', '=', 'order_pva.order_id')
                      ->selectRaw('sum(order_pva.price * order_pva.quantity)')
                      ->whereColumn('orders.customer_id', 'customers.id')
                      ->where('orders.type', 'sale')
                      ->whereNull('orders.deleted_at'),
                  'lifetime_value'
              );

        $paginator = $query->paginate($perPage, ['*'], 'page', $currentPage);

        $formattedData = $paginator->getCollection()->map(function ($customer) {
            $primaryAddress = $customer->addresses->first();
            
            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->code,
                'note' => $customer->note,
                'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
                'primary_phone' => $customer->phones->first() ? $customer->phones->first()->title : null,
                'primary_address' => $primaryAddress ? ($primaryAddress->title . ', ' . $primaryAddress->city->title) : null,
                'city' => $primaryAddress ? $primaryAddress->city->title : null,
                'images' => $customer->images,
                'orders_count' => $customer->orders_count,
                'lifetime_value' => (float) $customer->lifetime_value ?? 0,
                'last_order_date' => $customer->latestOrder ? $customer->latestOrder->created_at->toIso8601String() : null,
                'created_at' => $customer->created_at->toIso8601String(),
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
     * Store a newly created customer in storage.
     */
    public static function store(Request $request, $local = 0)
    {
        $data = $request->all();
        // If the request is a single associative array, wrap it in an array so we can process it uniformly.
        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

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
            if ($local) {
                // In local mode (called from another controller), throw an exception or return a response
                return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
            }
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $customers = DB::transaction(function () use ($data) {
            $createdCustomers = [];
            foreach ($data as $item) {
                $customerData = \Illuminate\Support\Arr::only($item, ['name', 'email', 'customer_type_id', 'note']);
                $customerData['account_id'] = getAccountUser()->account_id;
                $customerData['code'] = DefaultCodeController::getAccountCode('Customer', $customerData['account_id']);

                $customer = Customer::create($customerData);

                if (isset($item['phones'])) {
                    foreach ($item['phones'] as $phoneData) {
                        $customer->phones()->create([
                            'title' => $phoneData['title'],
                            'account_id' => $customerData['account_id']
                        ]);
                        // Note: Attaching phone types would require a many-to-many pivot table for phone_phone_type
                    }
                }

                if (isset($item['addresses'])) {
                    $customer->addresses()->createMany($item['addresses']);
                }
                
                $createdCustomers[] = $customer->load('phones', 'addresses');
            }
            return collect($createdCustomers);
        });
        
        if ($local == 1) return $customers;

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

        // Calculate Stats
        $stats = DB::table('orders')
            ->selectRaw('COUNT(id) as total_valid_orders')
            ->selectRaw('SUM(CASE WHEN order_status_id = 11 THEN 1 ELSE 0 END) as returned_orders')
            ->where('customer_id', $id)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->first();

        $lifetimeValueResult = DB::table('orders')
            ->join('order_pva', 'orders.id', '=', 'order_pva.order_id')
            ->where('orders.customer_id', $id)
            ->where('orders.type', 'sale')
            ->whereIn('orders.order_status_id', [7, 10])
            ->whereNull('orders.deleted_at')
            ->sum(DB::raw('order_pva.price * order_pva.quantity'));

        $lifetimeValue = (float) $lifetimeValueResult;
        $totalOrders = (int) ($stats->total_valid_orders ?? 0);
        $returnedOrders = (int) ($stats->returned_orders ?? 0);

        $averageOrderValue = $totalOrders > 0 ? round($lifetimeValue / $totalOrders, 2) : 0;
        $returnRate = $totalOrders > 0 ? round(($returnedOrders / $totalOrders) * 100, 2) : 0;
        $tier = $lifetimeValue > 5000 ? 'VIP' : 'Regular';

        // Manually paginate the orders to format them
        $ordersPaginator = $customer->orders()
            ->with([
                'orderStatus',
                'orderPvas.productVariationAttribute.product.images',
                'orderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute'
            ])
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        $formattedOrders = $ordersPaginator->getCollection()->map(function ($order) {
            $orderTotal = 0;

            $products = $order->orderPvas->map(function ($orderPva) use (&$orderTotal) {
                $orderTotal += $orderPva->price * $orderPva->quantity;
                $pva = $orderPva->productVariationAttribute;
                
                if(!$pva) return null;

                $attributesText = $pva->variationAttribute->childVariationAttributes->map(function ($child) {
                    return $child->attribute->code;
                })->toArray();

                return [
                    'order_pva_id' => $orderPva->id,
                    'product' => $pva->product->title . " " . implode('-', $attributesText),
                    'reference' => $pva->product->reference,
                    'quantity' => $orderPva->quantity,
                    'price' => $orderPva->price,
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
                'type' => $order->type,
                'status' => $order->orderStatus ? $order->orderStatus->title : null,
                'total' => $orderTotal,
                'date' => $order->created_at->toIso8601String(),
                'products' => $products,
            ];
        });

        $customerData = [
            'id' => $customer->id,
            'name' => $customer->name,
            'code' => $customer->code,
            'note' => $customer->note,
            'customer_type' => $customer->customerType ? $customer->customerType->only('id', 'title') : null,
            'phones' => $customer->phones,
            'addresses' => $customer->addresses,
            'images' => $customer->images,
            'wallet_balance' => (float) $customer->wallet_balance,
            'discount_percent' => (float) $customer->discount_percent,
            'is_blacklisted' => (bool) $customer->is_blacklisted,
            'tags' => $customer->tags,
            'lifetime_value' => $lifetimeValue,
            'average_order_value' => $averageOrderValue,
            'return_rate' => $returnRate,
            'tier' => $tier,
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
        $customer = Customer::where('account_id', getAccountUser()->account_id)->find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $data = $request->all();
        if (!isset($data[0]) || !is_array($data[0])) {
            $data = [$data];
        }

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
             if ($isOrder) return response()->json([ 'Validation Error', $validator->errors() ]);
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($data, $customer) {
            $item = $data[0];
            $customer->update(\Illuminate\Support\Arr::only($item, ['name', 'email', 'customer_type_id', 'note']));

            // Sync Phones
            if (isset($item['phones'])) {
                $phoneIds = [];
                foreach ($item['phones'] as $phoneData) {
                    if (isset($phoneData['id'])) {
                        $phone = Phone::find($phoneData['id']);
                        if ($phone) {
                            $phone->update(['title' => $phoneData['title']]);
                            $phoneIds[] = $phone->id;
                        }
                    } else {
                        $newPhone = $customer->phones()->create([
                            'title' => $phoneData['title'],
                            'account_id' => $customer->account_id,
                        ]);
                        $phoneIds[] = $newPhone->id;
                    }
                }
                // Remove any phones not in the request
                $customer->phones()->whereNotIn('phones.id', $phoneIds)->delete();
            }

            // Sync Addresses
            if (isset($item['addresses'])) {
                $addressIds = [];
                foreach ($item['addresses'] as $addressData) {
                    if (isset($addressData['id'])) {
                        $address = Address::find($addressData['id']);
                        if ($address) {
                            $address->update($addressData);
                            $addressIds[] = $address->id;
                        }
                    } else {
                        $newAddress = $customer->addresses()->create($addressData);
                        $addressIds[] = $newAddress->id;
                    }
                }
                // Remove addresses not in the request
                $customer->addresses()->whereNotIn('addresses.id', $addressIds)->delete();
            }
        });
        
        $updatedCustomer = $customer->fresh(['phones', 'addresses.city']);
        
        if ($isOrder == 1) return collect([['phones' => $updatedCustomer->phones, 'addresses' => $updatedCustomer->addresses, 'customer' => $updatedCustomer]]);

        return response()->json([
            'statut' => 1,
            'data' => $updatedCustomer,
            'message' => 'Customer updated successfully'
        ]);
    }

    /**
     * Remove the specified customer from storage.
     */
    public function destroy($id)
    {
        $customer = Customer::where('account_id', getAccountUser()->account_id)->find($id);

        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $customer->delete();

        return response()->json(['statut' => 1, 'message' => 'Customer deleted successfully']);
    }

    public function logCall(Request $request, $id)
    {
        $customer = Customer::where('account_id', getAccountUser()->account_id)->find($id);
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

    public function toggleBlacklist($id)
    {
        $customer = Customer::where('account_id', getAccountUser()->account_id)->find($id);
        if (!$customer) {
            return response()->json(['statut' => 0, 'message' => 'Customer not found'], 404);
        }

        $customer->is_blacklisted = !$customer->is_blacklisted;
        $customer->save();

        return response()->json([
            'statut' => 1,
            'message' => 'Blacklist status toggled',
            'is_blacklisted' => $customer->is_blacklisted
        ]);
    }

    public function timeline($id)
    {
        $customer = Customer::where('account_id', getAccountUser()->account_id)->find($id);
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
        $orderCalls = \App\Models\OrderCall::whereHas('order', function($query) use ($customer) {
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
        $sortedTimeline = $timeline->filter(function($item) {
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

        $accountId = getAccountUser()->account_id;

        $primary = Customer::where('account_id', $accountId)->find($request->primary_id);
        $secondary = Customer::where('account_id', $accountId)->find($request->secondary_id);

        if (!$primary || !$secondary) {
            return response()->json(['statut' => 0, 'message' => 'One or both customers not found'], 404);
        }

        DB::beginTransaction();

        try {
            // Reassign Orders
            \App\Models\Order::where('customer_id', $secondary->id)->update(['customer_id' => $primary->id]);

            // Reassign Addresses
            $secondaryAddresses = $secondary->addresses()->pluck('addresses.id')->toArray();
            if (!empty($secondaryAddresses)) {
                $primary->addresses()->syncWithoutDetaching($secondaryAddresses);
                $secondary->addresses()->detach($secondaryAddresses);
            }

            // Reassign Phones
            $secondaryPhones = $secondary->phones()->pluck('phones.id')->toArray();
            if (!empty($secondaryPhones)) {
                $primary->phones()->syncWithoutDetaching($secondaryPhones);
                $secondary->phones()->detach($secondaryPhones);
            }

            // Reassign Calls
            \App\Models\CustomerCall::where('customer_id', $secondary->id)->update(['customer_id' => $primary->id]);

            // Soft Delete Secondary Customer
            $secondary->delete();

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
}
