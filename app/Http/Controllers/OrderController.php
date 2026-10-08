<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf as PDF;
use SimpleSoftwareIO\QrCode\Facades\QrCode;
use App\Models\Customer;
use App\Support\Orders\OrderOwnership;
use App\Support\Orders\DuplicateOrderGuard;
use App\Support\Orders\OrderAge;
use App\Support\Orders\OrderListFilters;
use App\Support\Orders\OrderQueues;
use App\Support\Orders\OrderReasons;
use App\Models\Source;
use App\Models\CustomerType;
use App\Models\City;
use App\Models\Pickup;
use App\Models\Region;
use App\Models\Comment;
use App\Models\Country;
use App\Models\Sector;
use App\Models\ProductVariationAttribute;
use App\Models\OrderPva;
use App\Models\Offer;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use App\Models\Supplier;
use App\Models\Brand;
use App\Models\Taxonomy;
use App\Models\Phone;
use App\Models\Order;
use App\Models\Product;
use App\Models\Account;
use App\Models\OrderStatus;
use App\Services\GoogleSheetsService;
use App\Services\AfraShippingService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{

    public function index(Request $request)
    {

        $request = collect($request->query())->toArray();
        // a page is at most 100 rows, whatever the client asks for
        $filter = [
            'limit' => max(1, min(100, (int) ($request['pagination']['per_page'] ?? 10))),
            'page' => max(0, (int) ($request['pagination']['current_page'] ?? 0)),
        ];

        // the sort column goes into SQL: only these are accepted
        $sortable = ['id', 'code', 'shipping_code', 'created_at', 'updated_at', 'order_status_id', 'carrier_price', 'discount', 'total', 'callback_at', 'status_age'];
        $sortBy = in_array($request['sort'][0]['column'] ?? null, $sortable, true) ? $request['sort'][0]['column'] : 'created_at';
        $sortOrder = strtolower((string) ($request['sort'][0]['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        // a workspace queue (confirmation.new, tracking.stuck...): an unknown one is an error, not "all orders"
        $queue = $request['queue'] ?? null;
        if ($queue !== null && ! OrderQueues::exists($queue)) {
            return response()->json(['statut' => 0, 'message' => 'Unknown queue.'], 422);
        }

        // filters shared with the workspace counters (OrderWorkspaceController::queueCounts)
        $ordersQuery = OrderListFilters::query($request)->withAvg([
            'reviewAnswers as review_score' => function ($query) {
                $query->whereHas('question', function ($q) {
                    $q->where('type', 'stars');
                });
            }
        ], 'answer_value');

        if ($queue !== null) {
            OrderQueues::apply($ordersQuery, $queue, OrderListFilters::ageOptions($request));
        }

        if ($sortBy === 'total') {
            $totalSubquery = OrderPva::selectRaw('SUM(price * quantity)')
                ->whereColumn('order_pva.order_id', 'orders.id')
                ->whereNotIn('order_pva.order_status_id', [2, 3]);

            $ordersQuery->select('orders.*')
                ->selectSub($totalSubquery, 'total_value')
                ->orderBy('total_value', $sortOrder);
        } elseif ($sortBy === 'status_age') {
            $ordersQuery->orderByRaw(OrderAge::daysSql() . ' ' . $sortOrder);
        } else {
            $ordersQuery->orderBy('orders.' . $sortBy, $sortOrder);
        }

        $total = $ordersQuery->count();
        $orders = $ordersQuery
            ->with([
                'parentOrder.orderStatus',
                'parentOrder.pickup',
                'parentOrder.shipment.shipmentType',
                'childOrders.orderStatus',
                'childOrders.pickup',
                'childOrders.shipment.shipmentType',
                'pickup.carrier.images',
                'shipment.shipmentType',
                'orderStatus',
                'review.answers.question',
                'review.user',
                // everything the rows below read is loaded here, once for the whole page
                'userCreated.user.images',
                'lastOrderComments' => fn ($q) => $q->where('type', 'comment')->with(['accountUser.user', 'orderStatus']),
                'customer.images',
                'customer.phones',
                'customer.addresses.city',
                'brandSource.brand.images',
                'brandSource.source.images',
                'assignee.user',
                'claimer.user',
                'afraOperation',
                ...self::orderPvaPaths('activeOrderPvas'),
                ...self::orderPvaPaths('inactiveOrderPvas'),
            ])
            ->skip($filter['page'] * $filter['limit'])
            ->take($filter['limit'])
            ->get();

        if (!function_exists('calculateTotalOrderScores')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }
        $scores = calculateTotalOrderScores($orders->pluck('id')->all());

        // days in the current status and unanswered calls (from the history), for the page only:
        // one query, not a subquery per filtered row
        $history = $orders->isEmpty() ? collect() : Order::whereIn('orders.id', $orders->pluck('id'))
            ->selectRaw('orders.id, ' . OrderAge::daysSql() . ' as days, ' . OrderReasons::attemptsSql() . ' as attempts')
            ->get()->keyBy('id');

        $datas = $orders->map(function ($data) use ($scores, $history) {
            $orderData = $data->only('id', 'code', 'shipping_code', 'note', 'order_id', 'type', 'created_at', 'updated_at', 'pickup_id', 'shipment_id');
            $orderData['reference'] = $data->code;

            // Include parent and child basic relationship info to map the lineage using eager loading
            if ($data->parentOrder) {
                $orderData['parent_order'] = $data->parentOrder->only('id', 'code', 'type', 'pickup_id', 'shipment_id');
                $orderData['parent_order']['status'] = $data->parentOrder->orderStatus ? $data->parentOrder->orderStatus->only('id', 'title') : null;
                $orderData['parent_order']['pickup'] = $data->parentOrder->pickup ? $data->parentOrder->pickup->only('id', 'code', 'title') : null;
                if ($data->parentOrder->shipment) {
                    $orderData['parent_order']['shipment'] = $data->parentOrder->shipment->only('id', 'code', 'title');
                    $orderData['parent_order']['shipment']['type'] = $data->parentOrder->shipment->shipmentType ? $data->parentOrder->shipment->shipmentType->only('id', 'code', 'title') : null;
                } else {
                    $orderData['parent_order']['shipment'] = null;
                }
            } else {
                $orderData['parent_order'] = null;
            }

            // Find any child orders that were created from this order (e.g. returns/exchanges)
            $orderData['child_orders'] = $data->childOrders->map(function ($child) {
                $childData = $child->only('id', 'code', 'type', 'pickup_id', 'shipment_id');
                $childData['status'] = $child->orderStatus ? $child->orderStatus->only('id', 'title') : null;
                $childData['pickup'] = $child->pickup ? $child->pickup->only('id', 'code', 'title') : null;
                if ($child->shipment) {
                    $childData['shipment'] = $child->shipment->only('id', 'code', 'title');
                    $childData['shipment']['type'] = $child->shipment->shipmentType ? $child->shipment->shipmentType->only('id', 'code', 'title') : null;
                } else {
                    $childData['shipment'] = null;
                }
                return $childData;
            });

            // Calculate score dynamically from account_user_order_status and order_comment tables
            $orderData['score'] = $scores[$data->id] ?? 0;

            if (!$orderData['shipping_code'])
                $orderData['shipping_code'] = "";
            $orderData['can_change'] = in_array($data->order_status_id, [1, 2, 3, 4, 5]) ? true : false;
            $orderData['user'] = $data->userCreated->map(function ($user) {
                return [
                    "id" => $user->id,
                    "firstname" => $user->user->firstname,
                    "lastname" => $user->user->lastname,
                    "images" => $user->user->images,
                ];
            });
            $orderData['comments'] = $data->lastOrderComments->map(function ($comment) {
                $data = [
                    "id" => $comment->id,
                    "comment" => $comment->comment_id,
                    "title" => $comment->title,
                    "created_at" => $comment->created_at,
                    "user" => $comment->accountUser?->user,
                    "status" => $comment->orderStatus?->only('id', 'title', 'statut'),
                ];
                $data["status"]['created_at'] = $comment->created_at;
                return $data;
            });
            if ($data->customer) {
                $orderData['customer'] = $data->customer->only('id', 'name');
                $names = explode(' ', $data->customer->name, 2);
                $orderData['customer']['first_name'] = $names[0] ?? '';
                $orderData['customer']['last_name'] = $names[1] ?? '';
                $orderData['customer']['images'] = $data->customer->images;
                $orderData['customer']['phones'] = $data->customer->phones->map(function ($phone) {
                    return $phone->only('id', 'title');
                });
                $orderData['customer']['address'] = $data->customer->addresses->map(function ($address) {
                    return $address->only('id', 'title', 'city');
                });
            } else {
                $orderData['customer'] = ['id' => null, 'name' => null, 'first_name' => '', 'last_name' => '', 'images' => [], 'phones' => [], 'address' => []];
            }
            $totalOrder = 0;
            $orderData['products'] = ($data->order_status_id == 2 ? $data->inactiveOrderPvas : $data->activeOrderPvas)->map(function ($actfOrderPva) use (&$totalOrder) {
                $totalOrder += $actfOrderPva->price * $actfOrderPva->quantity;
                $attributes = $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                    return $child->attribute->code;
                })->toArray();
                $productInfo = [
                    'id' => $actfOrderPva->productVariationAttribute->product->id,
                    'order_pva' => $actfOrderPva->id,
                    'price' => $actfOrderPva->price,
                    'quantity' => $actfOrderPva->quantity,
                    'images' => $actfOrderPva->productVariationAttribute->product->images->sortByDesc('created_at')->values(),
                    'productType' => $actfOrderPva->productVariationAttribute->product->productType,
                    'product' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                    'name' => $actfOrderPva->productVariationAttribute->product->title . " " . implode('-', $attributes),
                    'reference' => $actfOrderPva->productVariationAttribute->product->reference,
                    'productsize' => $actfOrderPva->productVariationAttribute->variationAttribute->id,
                    'attributes' => $actfOrderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($child) {
                        return [
                            "id" => $child->attribute->id,
                            "title" => $child->attribute->title,
                            "typeAttribute" => $child->attribute->typeAttribute->title,
                        ];
                    }),
                ];
                return $productInfo;
            });
            $orderData['total'] = $totalOrder + (float) $data->carrier_price - (float) $data->discount;
            $orderData['sync'] = $data->sync != 0 ? true : false;
            $orderData['discount'] = $data->discount;
            $orderData['carrier_price'] = $data->carrier_price;
            $orderData['status'] = $data->orderStatus ? $data->orderStatus->only('id', 'title') : null;
            $orderData['pickup'] = $data->pickup ? $data->pickup->only('id', 'code', 'title') : null;
            if ($data->shipment) {
                $orderData['shipment'] = $data->shipment->only('id', 'code', 'title');
                $orderData['shipment']['type'] = $data->shipment->shipmentType ? $data->shipment->shipmentType->only('id', 'code', 'title') : null;
            } elseif ($data->parentOrder && $data->parentOrder->shipment) {
                $orderData['shipment'] = $data->parentOrder->shipment->only('id', 'code', 'title');
                $orderData['shipment']['type'] = $data->parentOrder->shipment->shipmentType ? $data->parentOrder->shipment->shipmentType->only('id', 'code', 'title') : null;
            } else {
                $orderData['shipment'] = null;
            }
            $orderData['brand'] = $data->brandSource && $data->brandSource->brand ? $data->brandSource->brand->only('id', 'title', 'images') : null;
            $orderData['carrier'] = ($data->pickup) ? $data->pickup->carrier->only('id', 'title', 'images') : null;
            $source = $data->brandSource->source;
            $sourceArr = $source->only('id', 'title', 'images');
            if (isset($source->images) && $source->images instanceof \Illuminate\Support\Collection) {
                $sourceArr['images'] = $source->images->sortByDesc('created_at')->values();
            }
            $orderData['source'] = $sourceArr;
            $orderData['review_score'] = isset($data->review_score) ? (float) round($data->review_score, 2) : null;

            // workspace fields: owner, callback, focus-mode claim, carrier status, time in status, unanswered calls
            $orderData = array_merge($orderData, self::workspaceFields($data));
            $orderData['status_age_days'] = isset($history[$data->id]) ? (int) $history[$data->id]->days : null;
            $orderData['attempts'] = isset($history[$data->id]) ? (int) $history[$data->id]->attempts : 0;

            // Format reviews
            $orderData['reviews'] = [];
            if ($data->review && $data->review->answers) {
                $orderData['reviews'] = $data->review->answers->map(function ($answer) use ($data) {
                    return [
                        'id' => $answer->id,
                        'question_id' => $answer->review_question_id,
                        'question' => $answer->question ? $answer->question->only('id', 'text', 'type') : null,
                        'answer' => $answer->answer_value,
                        'created_at' => $data->review->created_at,
                        'user' => $data->review->user ? $data->review->user->only('id', 'name', 'firstname', 'lastname') : null,
                    ];
                });
            }
            return $orderData;
        });

        return [
            'statut' => 1,
            'data' => $datas,
            'per_page' => (int) ($filter['limit'] ?? 10),
            'current_page' => (int) ($filter['page'] ?? 0) + 1,
            'total' => $total,
            'score' => 30
        ];
    }

    public function countByPhones(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phones' => 'required|array',
            'phones.*' => 'string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $phones = collect($request->input('phones', []))
            ->filter(function ($phone) {
                return is_string($phone) && trim($phone) !== '';
            })
            ->map(function ($phone) {
                return trim($phone);
            })
            ->unique()
            ->values()
            ->all();

        $result = [];
        foreach ($phones as $phone) {
            $result[$phone] = 0;
        }

        if (empty($phones)) {
            return $result;
        }

        // Extract last 8 digits from each phone number (ignoring all non-digit characters)
        $phoneLast8Map = [];
        foreach ($phones as $phone) {
            // Remove all non-digit characters
            $digitsOnly = preg_replace('/\D/', '', $phone);
            // Get last 8 digits
            $last8 = substr($digitsOnly, -8);
            // Only process if we have exactly 8 digits
            if (strlen($last8) === 8) {
                $phoneLast8Map[$last8] = $phone;
            }
        }

        if (empty($phoneLast8Map)) {
            return $result;
        }

        // Build a LIKE query that matches the last 8 digits in any format
        // Pattern: %d%i%g%i%t%1%...%digit8% matches digits in sequence with any chars between
        $phoneCounts = Phone::where(function ($query) use ($phoneLast8Map) {
            foreach (array_keys($phoneLast8Map) as $last8) {
                // Split into individual digits and join with % wildcard
                // This will match "44001015", "44-00-10-15", "44 00 10 15", etc.
                $pattern = '%' . implode('%', str_split($last8)) . '%';
                $query->orWhere('title', 'like', $pattern);
            }
        })
            ->withCount([
                'orders as orders_count' => function ($query) {
                    $query->where('account_id', getAccountUser()->account_id);
                }
            ])
            ->get(['id', 'title']);

        // Map results back to original phone numbers by comparing last 8 digits
        foreach ($phoneCounts as $phoneModel) {
            // Extract digits from database phone and get last 8
            $dbPhoneDigits = preg_replace('/\D/', '', $phoneModel->title);
            $dbLast8 = substr($dbPhoneDigits, -8);

            // If this matches one of our searched phones, add the count
            if (isset($phoneLast8Map[$dbLast8])) {
                $originalPhone = $phoneLast8Map[$dbLast8];
                $result[$originalPhone] += (int) $phoneModel->orders_count;
            }
        }

        return $result;
    }

    /** Relations the list reads for every order line, loaded once per page. */
    private static function orderPvaPaths(string $relation): array
    {
        return [
            "{$relation}.productVariationAttribute.variationAttribute.childVariationAttributes.attribute.typeAttribute",
            "{$relation}.productVariationAttribute.product.images",
            "{$relation}.productVariationAttribute.product.productType",
        ];
    }

    /** An agent (account_user) as the order screens show it. */
    public static function agentSummary($accountUser): ?array
    {
        if (! $accountUser) {
            return null;
        }
        $user = $accountUser->user;
        $name = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));

        return [
            'id' => $accountUser->id,
            'firstname' => $user->firstname ?? null,
            'lastname' => $user->lastname ?? null,
            'name' => $name !== '' ? $name : ($user->name ?? null),
        ];
    }

    /** What the order workspaces show on an order: owner, callback ("Reporté" date), focus-mode claim, carrier status. */
    public static function workspaceFields(Order $order): array
    {
        $claimed = $order->claimed_by && $order->claimed_until && $order->claimed_until->isFuture();

        return [
            'assignee' => self::agentSummary($order->assignee),
            'assigned_at' => $order->assigned_at,
            'callback_at' => $order->callback_at,
            'claim' => $claimed ? ['agent' => self::agentSummary($order->claimer), 'until' => $order->claimed_until] : null,
            'carrier_status' => $order->afraOperation?->remote_status
                ? ['status' => $order->afraOperation->remote_status, 'at' => $order->afraOperation->remote_status_at]
                : null,
        ];
    }

    public function counts(Request $request)
    {
        $accountId = getAccountUser()->account_id;

        $counts = Order::where('account_id', $accountId)
            ->select('order_status_id', DB::raw('count(*) as count'))
            ->groupBy('order_status_id')
            ->pluck('count', 'order_status_id')
            ->all();

        $allCount = Order::where('account_id', $accountId)->count();

        $pendingConfirmation = $counts[1] ?? 0;
        $confirmed = ($counts[4] ?? 0) + ($counts[5] ?? 0);
        $inTransit = $counts[6] ?? 0;
        $pendingPayments = $counts[7] ?? 0;
        $pendingReturns = ($counts[8] ?? 0) + ($counts[9] ?? 0);

        // the workspace badges of the menu: what needs someone now
        $queueCount = fn (string $queue) => OrderQueues::apply(OrderListFilters::query([]), $queue)->count();

        return response()->json([
            'statut' => 1,
            'data' => [
                'all' => $allCount,
                'pending_confirmation' => $pendingConfirmation,
                'confirmed' => $confirmed,
                'in_transit' => $inTransit,
                'In transit' => $inTransit,
                'pending_payments' => $pendingPayments,
                'pending_returns' => $pendingReturns,
                'to_confirm' => $queueCount('confirmation.new') + $queueCount('confirmation.callbacks_due'),
                'stuck' => $queueCount('tracking.stuck'),
                'unpaid' => $queueCount('recovery.unpaid'),
            ]
        ]);
    }

    public function create(Request $request)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        if (isset($request['products']['inactive'])) {
            $model = 'App\\Models\\Product';
            //permet de récupérer la liste des regions inactive filtrés
            $request['products']['inactive']['inAccountUser'] = ['account_user_id', getAccountUser()->account_id];
            $associated[] = [
                'model' => 'App\\Models\\ProductVariationAttribute',
                'title' => 'productVariationAttributes.variationAttribute.childVariationAttributes.attribute.typeAttribute',
                'search' => false
            ];
            $associated[] = [
                'model' => 'App\\Models\\ProductVariationAttribute',
                'title' => 'productVariationAttributes.pvaPacks.childPvaPacks.ProductVariationAttribute.Product',
                'search' => false
            ];

            $products = FilterController::searchs(new Request($request['products']['inactive']), $model, ['id', 'title'], false, $associated)->map(function ($product) {
                $productData = $product->only('id', 'title');
                $productData['productType'] = $product->productType;
                $productData['images'] = $product->images;
                $productData['productVariations'] = $product->productVariationAttributes->map(function ($productVariationAttribute) use ($product) {
                    if ($product->product_type_id == 1) {
                        $pvaData = ["id" => $productVariationAttribute->id];
                        $pvaData['variations'] = $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                            if ($childVariationAttribute->attribute->typeAttribute)
                                return [
                                    "id" => $childVariationAttribute->id,
                                    "type" => $childVariationAttribute->attribute->typeAttribute->title,
                                    "value" => $childVariationAttribute->attribute->title
                                ];
                        })->filter();
                        return $pvaData;
                    } else {
                        $pvaData = ["id" => $productVariationAttribute->id];
                        $pvaData['variations'] = $productVariationAttribute->pvaPacks->first()->childPvaPacks->map(function ($childPvaPack) {
                            return ["id" => $childPvaPack->id, "type" => "product", "value" => $childPvaPack->productVariationAttribute->product->title];
                        })->values();
                        return $pvaData;
                    }
                });
                return $productData;
            });
            $data['products']['inactive'] = HelperFunctions::getPagination($products, $request['products']['inactive']['pagination']['per_page'], $request['products']['inactive']['pagination']['current_page']);
        }

        return response()->json([
            'statut' => 1,
            'data' => $data,
        ]);
    }


    public static function store(Request $requests, $isImport = 0)
    {
        // Normalize incoming payload (array of orders) and remove method spoofing key.
        $ordersPayload = collect($requests->except('_method'))->values();

        // Cache account context once to avoid repeated helper calls.
        $accountUser = getAccountUser();
        $accountId = $accountUser->account_id;

        // This array stores the resolved PVA rows per order index.
        $resolvedPvas = [];

        // Resolve the pivot id of each offer relation (product, brand, city, etc.).
        $resolveOfferablePivotIds = function (array $offerIds) {
            return collect($offerIds)->map(function ($offerId) {
                $offer = Offer::find($offerId);
                if (!$offer) {
                    return null;
                }
                if ($offer->productVariationAttributes->count() > 0) {
                    return $offer->productVariationAttributes->first()->pivot->id;
                }
                if ($offer->products->count() > 0) {
                    return $offer->products->first()->pivot->id;
                }
                if ($offer->taxonomies->count() > 0) {
                    return $offer->taxonomies->first()->pivot->id;
                }
                if ($offer->sources->count() > 0) {
                    return $offer->sources->first()->pivot->id;
                }
                if ($offer->brands->count() > 0) {
                    return $offer->brands->first()->pivot->id;
                }
                if ($offer->brandSources->count() > 0) {
                    return $offer->brandSources->first()->pivot->id;
                }
                if ($offer->customers->count() > 0) {
                    return $offer->customers->first()->pivot->id;
                }
                if ($offer->customerTypes->count() > 0) {
                    return $offer->customerTypes->first()->pivot->id;
                }
                if ($offer->cities->count() > 0) {
                    return $offer->cities->first()->pivot->id;
                }
                if ($offer->countries->count() > 0) {
                    return $offer->countries->first()->pivot->id;
                }
                if ($offer->regions->count() > 0) {
                    return $offer->regions->first()->pivot->id;
                }
                if ($offer->sectors->count() > 0) {
                    return $offer->sectors->first()->pivot->id;
                }

                return null;
            })->filter()->values()->all();
        };

        // Generate order code and append brand/source initials when available.
        $generateOrderCode = function (array $orderData) use ($accountId) {
            $suffix = '';
            if (!empty($orderData['brand_source_id'])) {
                $brandSource = \App\Models\BrandSource::with(['brand', 'source'])->find($orderData['brand_source_id']);
                if ($brandSource && $brandSource->brand && $brandSource->source) {
                    $suffix = strtoupper(substr($brandSource->brand->title, 0, 1)) . strtoupper(substr($brandSource->source->title, 0, 1));
                }
            }

            // A code supplied by the caller (imports) is kept as is.
            if (isset($orderData['code'])) {
                return $orderData['code'] . $suffix;
            }

            // Generated codes: the counter is atomic, but the final code (counter + 2 random letters +
            // brand/source initials) is still checked so a collision never reaches the database.
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $code = DefaultCodeController::getAccountCode('Order', $accountId) . $suffix;

                if (!DB::table('orders')->where('account_id', $accountId)->where('code', $code)->exists()) {
                    return $code;
                }
            }

            return $code;
        };

        // Validate payload and resolve product variations when request is not import mode.
        if ($isImport == 0) {
            $phoneableType = Customer::class;
            $validator = Validator::make($ordersPayload->toArray(), [
                '*.warehouse_id' => [
                    'nullable',
                    'int',
                    function ($attribute, $value, $fail) use ($accountId) {
                        if ($value === null) {
                            return;
                        }
                        $warehouse = Warehouse::where(['id' => $value, 'account_id' => $accountId])->first();
                        if (!$warehouse) {
                            $fail('not exist');
                        }
                    },
                ],
                '*.payment_type_id' => 'nullable|exists:payment_types,id|max:255',
                '*.payment_method_id' => 'nullable|exists:payment_methods,id|max:255',
                '*.customer.id' => [
                    'nullable',
                    function ($attribute, $value, $fail) use ($accountId) {
                        if ($value === null) {
                            return;
                        }
                        $customer = Customer::where('id', $value)->where('account_id', $accountId)->first();
                        if (!$customer) {
                            $fail('not exist');
                        }
                    },
                ],
                '*.customer.name' => 'nullable|max:255',
                '*.customer.phones.*.title' => [
                    'nullable',
                    'string',
                ],
                '*.customer.phones.*.phoneTypes' => 'nullable|array',
                '*.customer.phones.*.phoneTypes.*' => 'exists:phone_types,id|max:255',
                '*.customer.customer_type_id' => 'nullable|exists:customer_types,id|max:255',
                '*.customer.addresses.*.title' => 'nullable|max:255',
                '*.customer.addresses.*.city_id' => 'nullable|exists:cities,id|max:255',
                '*.sector_id' => 'nullable|exists:sectors,id|max:255',
                '*.order_status_id' => 'nullable|exists:order_statuses,id|max:255',
                '*.brand_source_id' => ['nullable', 'exists:brand_source,id', fn ($attribute, $value, $fail) => OrderOwnership::ownsBrandSource($value) ?: $fail('not exist')],
                '*.products' => 'required|array|min:1',
                '*.products.*.offers' => 'nullable|array',
                '*.products.*.offers.*' => 'exists:offers,id|max:255',
                '*.products.*.attributes' => 'required|array|min:1',
                '*.products.*.attributes.*' => 'exists:attributes,id|max:255',
                '*.products.*.quantity' => 'required|numeric|min:1',
                '*.products.*.price' => 'nullable|numeric',
                '*.products.*.discount' => 'nullable|numeric',
                '*.products.*.id' => 'required|int',
                '*.discount' => 'nullable|numeric',
                '*.carrier_price' => 'nullable|numeric',
                '*.scarrier_price' => 'nullable|numeric',
            ]);

            // Stop early when request shape is invalid.
            if ($validator->fails()) {
                return response()->json([
                    'statut' => 0,
                    'data' => $validator->errors(),
                ], 422);
            }

            // Build the list of account users allowed to own products.
            $accountUsers = Account::find($accountId)?->accountUsers->pluck('id')->toArray() ?? [];

            // Resolve each product to its matching variation based on selected attributes.
            foreach ($ordersPayload as $orderIndex => $orderData) {
                $resolvedPvas[$orderIndex] = [];

                foreach (($orderData['products'] ?? []) as $productData) {
                    $selectedAttributes = collect($productData['attributes'] ?? [])->map(function ($id) {
                        return (int) $id;
                    })->sort()->values()->all();

                    $product = Product::with('productVariationAttributes.variationAttribute.childVariationAttributes')
                        ->where('id', $productData['id'])
                        ->whereIn('account_user_id', $accountUsers)
                        ->first();

                    if (!$product) {
                        return response()->json([
                            'statut' => 0,
                            'data' => ["$orderIndex.products" => ['not exist']],
                        ], 422);
                    }

                    $matchedPva = null;
                    foreach ($product->productVariationAttributes as $pva) {
                        $pvaAttributes = $pva->variationAttribute->childVariationAttributes
                            ->pluck('attribute_id')
                            ->map(function ($id) {
                                return (int) $id;
                            })
                            ->sort()
                            ->values()
                            ->all();

                        if ($pvaAttributes === $selectedAttributes) {
                            $matchedPva = $pva;
                            break;
                        }
                    }

                    if (!$matchedPva) {
                        return response()->json([
                            'statut' => 0,
                            'data' => ["$orderIndex.products" => ['not Exists']],
                        ], 422);
                    }

                    $resolvedPvas[$orderIndex][] = [
                        'id' => $matchedPva->id,
                        'price' => $productData['price'] ?? null,
                        'discount' => $productData['discount'] ?? 0,
                        'offerables' => $resolveOfferablePivotIds($productData['offers'] ?? []),
                        'quantity' => $productData['quantity'],
                    ];
                }
            }
        }

        $duplicateGuard = new DuplicateOrderGuard();

        try {
            // Create all orders in a single transaction to keep data consistent.
            $orderIds = DB::transaction(function () use ($ordersPayload, $resolvedPvas, $isImport, $accountUser, $accountId, $generateOrderCode, $duplicateGuard) {
                return $ordersPayload->map(function ($request, $index) use ($resolvedPvas, $isImport, $accountUser, $accountId, $generateOrderCode, $duplicateGuard) {
                    // Duplicate guard (non-import creation only): same phone, same products and quantities
                    // within a few minutes. It runs before the customer is created or updated (an update can rewrite the
                    // phone title). The lock makes the check and the insert one step for concurrent requests.
                    if ($isImport == 0 && isset($request['customer']['phones']) && count($request['customer']['phones']) > 0) {
                        $phoneTitles = DuplicateOrderGuard::normalizePhones(collect($request['customer']['phones'])->pluck('title')->all());

                        if (count($phoneTitles) > 0) {
                            if (!$duplicateGuard->acquire($accountId, $phoneTitles)) {
                                throw new \Exception('Another order for this phone number is being created. Try again in a moment.');
                            }
                            // kept until the transaction ends, so a concurrent request sees our order
                            $duplicateGuard->releaseAfterTransaction();

                            $duplicate = $duplicateGuard->findDuplicate($accountId, $phoneTitles, $resolvedPvas[$index] ?? [], (int) config('orders.duplicate_window_minutes', 5));
                            if ($duplicate) {
                                throw new \Exception('Duplicate order detected for phone number(s): ' . implode(', ', $phoneTitles) . ' (order ' . $duplicate->code . ')');
                            }
                        }
                    }

                    // Resolve or create the customer linked to the order.
                    $customer = null;
                    if (isset($request['customer']['id'])) {
                        $customer = Customer::where('id', $request['customer']['id'])->where('account_id', $accountId)->first();
                        if ($customer) {
                            $updateResult = CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                            if ($updateResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $updateResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer update failed.';
                                throw new \Exception($message);
                            }
                        }
                    } elseif (isset($request['customer']['phones'])) {
                        $request['customer']['customer_type_id'] = $request['customer']['customer_type_id'] ?? 1;
                        $request['customer']['name'] = (isset($request['customer']['name']) && trim($request['customer']['name']) !== '') ? $request['customer']['name'] : 'client';

                        // Reuse an existing customer when one of the provided phones already exists.
                        $phoneTitles = collect($request['customer']['phones'])
                            ->pluck('title')
                            ->filter()
                            ->map(function ($title) {
                                return formatPhoneNumber($title);
                            })
                            ->values()
                            ->all();

                        $phoneWithCustomer = null;
                        if (!empty($phoneTitles)) {
                            $phoneWithCustomer = Phone::with('customers')
                                ->where('account_id', $accountId)
                                ->whereIn('title', $phoneTitles)
                                ->whereHas('customers')
                                ->orderBy('created_at', 'DESC')
                                ->first();
                        }

                        if ($phoneWithCustomer && $phoneWithCustomer->customers->first()) {
                            $customer = $phoneWithCustomer->customers->first();
                            $request['customer']['id'] = $customer->id;

                            $updateResult = CustomerController::update(new Request([$request['customer']]), $customer->id, $isOrder = 1);
                            if ($updateResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $updateResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer update failed.';
                                throw new \Exception($message);
                            }
                        } else {
                            $customerData = new Request([$request['customer']]);
                            $customerResult = CustomerController::store($customerData, 1);
                            // CustomerController::store returns a JsonResponse on validation failure even in local mode.
                            // Unwrap the collection or surface the validation message as an exception.
                            if ($customerResult instanceof \Illuminate\Http\JsonResponse) {
                                $payload = $customerResult->getData(true);
                                $message = isset($payload[1]) ? json_encode($payload[1]) : 'Customer validation failed.';
                                throw new \Exception($message);
                            }

                            $customer = $customerResult->first();
                        }
                    }

                    // Ensure customer exists when a customer id is required.
                    if (!$customer) {
                        throw new \Exception('Customer is required to create order.');
                    }

                    // Fill required order ownership fields.
                    $request['account_id'] = $accountId;
                    $request['customer_id'] = $customer->id;
                    $request['order_status_id'] = 1;

                    // Build business order code for non-import flow.
                    if ($isImport == 0) {
                        $request['code'] = $generateOrderCode($request);
                    }

                    // Fallback to first account warehouse when none was sent.
                    if (!isset($request['warehouse_id'])) {
                        $warehouse = Warehouse::where('account_id', $accountId)->first();
                        if (!$warehouse) {
                            throw new \Exception('No warehouse found for account.');
                        }
                        $request['warehouse_id'] = $warehouse->id;
                    }

                    // Persist the order row. A client-built order must not set fields the system owns
                    // (carrier links, billing, timestamps, sync flags); imports are trusted and keep them.
                    $orderData = $isImport == 0
                        ? \Illuminate\Support\Arr::except($request, ['pickup_id', 'shipment_id', 'invoice_id', 'shipping_code', 'real_carrier_price', 'created_at', 'updated_at', 'sync', 'type'])
                        : $request;
                    $order = Order::create($orderData);

                    // For import mode, attach first customer phone/address and use provided order_pva.
                    if ($isImport == 1 || $isImport == 2) {
                        $requestPvas = $request['order_pva'] ?? [];

                        if ($order->customer && $order->customer->addresses->first()) {
                            $address = $order->customer->addresses->first();
                            $order->addresses()->syncWithoutDetaching([$address->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                        }

                        if ($order->customer && $order->customer->phones->first()) {
                            $phone = $order->customer->phones->first();
                            $order->phones()->syncWithoutDetaching([$phone->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                        }
                    } else {
                        $requestPvas = $resolvedPvas[$index] ?? [];

                        // Attach customer addresses to the order and set city from matched address.
                        foreach (($request['customer']['addresses'] ?? []) as $addressData) {
                            $customerAddress = $customer->addresses->where('title', $addressData['title'] ?? null)->first();
                            if ($customerAddress) {
                                $order->update(['city_id' => $customerAddress->city_id]);
                                $order->addresses()->syncWithoutDetaching([$customerAddress->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                            }
                        }

                        // Attach customer phones to the order.
                        foreach (($request['customer']['phones'] ?? []) as $phoneData) {
                            $customerPhone = $customer->phones->where('title', $phoneData['title'] ?? null)->first();
                            if ($customerPhone) {
                                $order->phones()->syncWithoutDetaching([$customerPhone->id => ['statut' => 1, 'created_at' => now(), 'updated_at' => now()]]);
                            }
                        }
                    }

                    // Attach each resolved product variation to order with computed pricing fields.
                    foreach ($requestPvas as $pvaData) {
                        $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                        if (!$productVariationAttribute) {
                            continue;
                        }

                        $product = Product::find($productVariationAttribute->product_id);

                        // price() is morphToMany → always a Collection, but may be empty.
                        $initialPrice = $product?->price->first()?->price ?? 0;

                        $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initialPrice;
                        $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;

                        // orderPvas is not defined on Product, so the property is null.
                        // Use optional() so ->first() on null returns null safely instead of throwing.
                        $realPrice = optional($product?->orderPvas)->first()?->price ?? 0;

                        $productVariationAttribute->orders()->attach($order->id, [
                            'quantity' => $pvaData['quantity'],
                            'price' => $productPrice,
                            'realprice' => $realPrice,
                            'initial_price' => $initialPrice,
                            'discount' => $discount,
                            'order_status_id' => 1,
                            'account_user_id' => $accountUser->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        // Persist offer variation links when provided.
                        if (!empty($pvaData['offerables'])) {
                            VariationOfferableController::store(new Request([
                                'order_id' => $order->id,
                                'pva' => $productVariationAttribute,
                                'variations' => $pvaData['offerables'],
                            ]));
                        }
                    }

                    // Sync order creation status to Google Sheets (if enabled).
                    $syncRequested = isset($request['sync_google_sheet']) ? filter_var($request['sync_google_sheet'], FILTER_VALIDATE_BOOLEAN) : true;
                    if (config('google-sheets.enabled') && $syncRequested) {
                        try {
                            app(GoogleSheetsService::class)->appendOrderStatusRow(
                                $order,
                                null,
                                $accountUser,
                                'Nouvelle commande créée'
                            );

                            $order->comments()->syncWithoutDetaching([
                                44 => [
                                    'title' => 'Sync with Google Sheets',
                                    'order_status_id' => 1,
                                    'account_user_id' => $accountUser->id,
                                    'score' => 0,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]
                            ]);

                            $order->sync = true;
                            $order->save();
                        } catch (\Throwable $e) {
                            $order->sync = false;
                            $order->save();
                            Log::warning('Google Sheets sync failed for order ' . $order->id . ': ' . $e->getMessage());
                        }
                    }

                    // Return only created order id to preserve endpoint response contract.
                    return $order->id;
                });
            });

            // Return success payload with all created order ids.
            return response()->json([
                'statut' => 1,
                'data' => $orderIds,
            ]);
        } catch (\Throwable $e) {
            $duplicateGuard->release();
            // Return a safe error payload and log details for debugging.
            Log::error('Order store failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'statut' => 0,
                'data' => $e->getMessage(),
            ], 422);
        }
    }
    public function generatePdf($id)
    {
        $order = Order::findOrFail($id);
        QrCode::size(100)->generate($order->code, public_path('qrcodes/' . $order->code . '.png'));
        $total = 0;
        $order->activePvas->map(function ($activePva) use (&$total) {
            $total += $activePva->pivot->quantity * $activePva->pivot->price;
        });
        $datas['datas'][] = [
            "code" => $order->code,
            "sender" => $order->brandSource->brand->title,
            "sender_mail" => $order->brandSource->brand->email,
            "sender_phone" => $order->brandSource->brand,
            "customer" => $order->customer->name,
            "address" => ($order->addresses->first()?->title ?? '') . "-" . ($order->city?->title ?? ''),
            "phones" => $order->phones->map(function ($phone) {
                return $phone->title;
            }),
            "products" => $order->activePvas->map(function ($activePva) {
                $variations = $activePva->variationAttribute->childVariationAttributes->map(function ($childVa) {
                    return $childVa->attribute->title;
                });
                return $activePva->pivot->quantity . " x " . $activePva->product->title . ' : ' . implode(", ", $variations->toArray());
            }),
            "total" => $total . ' DH ',
            'qr_code' => "{$order->code}.png" // QR code for the tracking number
        ];
        $html = view('pdf.tickets', $datas)->render();

        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8',
            'orientation' => 'P', // P for Portrait, L for Landscape
            'margin_left' => 10,
            'margin_right' => 10,
            'margin_top' => 10,
            'margin_bottom' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
        ]);

        $mpdf->WriteHTML($html);
        $pdfContent = $mpdf->Output('', 'S'); // Output as a string

        // Encode PDF to Base64
        $base64Pdf = base64_encode($pdfContent);

        // Return JSON response with Base64 PDF
        return response()->json([
            'statut' => 1,
            'data' => $base64Pdf,
        ]);
    }

    public function show($id)
    {
        //
    }

    public function edit(Request $request, $id)
    {
        $request = collect($request->query())->toArray();
        $data = [];
        $order = Order::find($id);
        if (!$order)
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ], 404);
        if (isset($request['orderInfo'])) {
            $data['orderInfo'] = $order->only(['id', 'code', 'type', 'carrier_price', 'shipping_code', 'discount', 'created_at', 'updated_at', 'pickup_id', 'shipment_id']);

            // Parent order logic
            if ($order->order_id) {
                $parentOrder = Order::with(['orderStatus', 'pickup', 'shipment.shipmentType'])->find($order->order_id);
                if ($parentOrder) {
                    $data['orderInfo']['parent_order'] = $parentOrder->only('id', 'code', 'type', 'pickup_id', 'shipment_id');
                    $data['orderInfo']['parent_order']['status'] = $parentOrder->orderStatus ? $parentOrder->orderStatus->only('id', 'title') : null;
                    $data['orderInfo']['parent_order']['pickup'] = $parentOrder->pickup ? $parentOrder->pickup->only('id', 'code', 'title') : null;
                    if ($parentOrder->shipment) {
                        $data['orderInfo']['parent_order']['shipment'] = $parentOrder->shipment->only('id', 'code', 'title');
                        $data['orderInfo']['parent_order']['shipment']['type'] = $parentOrder->shipment->shipmentType ? $parentOrder->shipment->shipmentType->only('id', 'code', 'title') : null;
                    } else {
                        $data['orderInfo']['parent_order']['shipment'] = null;
                    }
                } else {
                    $data['orderInfo']['parent_order'] = null;
                }
            } else {
                $data['orderInfo']['parent_order'] = null;
            }

            // Child orders logic
            $childOrders = Order::with(['orderStatus', 'pickup', 'shipment.shipmentType'])->where('order_id', $order->id)->get();
            $data['orderInfo']['child_orders'] = $childOrders->map(function ($child) {
                $childData = $child->only('id', 'code', 'type', 'pickup_id', 'shipment_id');
                $childData['status'] = $child->orderStatus ? $child->orderStatus->only('id', 'title') : null;
                $childData['pickup'] = $child->pickup ? $child->pickup->only('id', 'code', 'title') : null;
                if ($child->shipment) {
                    $childData['shipment'] = $child->shipment->only('id', 'code', 'title');
                    $childData['shipment']['type'] = $child->shipment->shipmentType ? $child->shipment->shipmentType->only('id', 'code', 'title') : null;
                } else {
                    $childData['shipment'] = null;
                }
                return $childData;
            });

            $orderCustomer = $order->customer;
            $data['orderInfo']['customer'] = $orderCustomer
                ? $orderCustomer->only('id', 'name', 'note')
                : ['id' => null, 'name' => null, 'note' => null];
            $data['orderInfo']['customer']['addresses'] = $orderCustomer
                ? $orderCustomer->addresses->map(function ($address) {
                    $addressData = $address->only('id', 'title');
                    $addressData['city'] = $address->city ? $address->city->only('id', 'title') : null;
                    return $addressData;
                })
                : [];
            $data['orderInfo']['customer']['phones'] = $orderCustomer
                ? $orderCustomer->phones->map(function ($phone) {
                    $phoneData = $phone->only('id', 'title');
                    $phoneData['phoneTypes'] = $phone->phoneTypes->map(function ($phoneType) {
                        return $phoneType->only('id', 'title');
                    });
                    return $phoneData;
                })
                : [];
            $data['orderInfo']['warehouse'] = $order->warehouse;
            $data['orderInfo']['payment_type'] = $order->paymentType ? $order->paymentType->only('id', 'title') : null;

            $data['orderInfo']['pickup'] = $order->pickup ? $order->pickup->only('id', 'code', 'title', 'carrier_id') : null;
            $data['orderInfo']['carrier'] = $order->pickup?->carrier?->only('id', 'title');
            $afraOperation = AfraShippingService::isAfraOrder($order)
                ? \App\Models\AfraOrderOperation::where('order_id', $order->id)->first() : null;
            $data['orderInfo']['afra_return_state'] = (int) $order->order_status_id === AfraShippingService::RETURN_STATUS
                ? $afraOperation?->return_state : null;
            // failed / uncertain → the order page offers "Renvoyer à Afra"
            $data['orderInfo']['afra_create_state'] = $order->shipping_code ? null : $afraOperation?->create_state;
            $data['orderInfo']['afra_last_error'] = $afraOperation?->last_error;
            $data['orderInfo']['afra_status'] = $afraOperation?->remote_status;
            $data['orderInfo']['afra_status_at'] = $afraOperation?->remote_status_at;
            $data['orderInfo']['is_afra'] = (bool) $afraOperation || AfraShippingService::isAfraOrder($order);

            // workspace fields; unanswered calls are counted from the history (the "no answer" reasons)
            $order->load('assignee.user', 'claimer.user', 'afraOperation');
            $data['orderInfo'] = array_merge($data['orderInfo'], self::workspaceFields($order));
            $data['orderInfo']['attempts'] = (int) Order::whereKey($order->id)->selectRaw(OrderReasons::attemptsSql() . ' as attempts')->value('attempts');

            if ($order->shipment) {
                $data['orderInfo']['shipment'] = $order->shipment->only('id', 'code', 'title');
                $data['orderInfo']['shipment']['type'] = $order->shipment->shipmentType ? $order->shipment->shipmentType->only('id', 'code', 'title') : null;
            } elseif ($order->parentOrder && $order->parentOrder->shipment) {
                $data['orderInfo']['shipment'] = $order->parentOrder->shipment->only('id', 'code', 'title');
                $data['orderInfo']['shipment']['type'] = $order->parentOrder->shipment->shipmentType ? $order->parentOrder->shipment->shipmentType->only('id', 'code', 'title') : null;
            } else {
                $data['orderInfo']['shipment'] = null;
            }

            $data['orderInfo']['payment_method'] = $order->paymentMethod ? $order->paymentMethod->only('id', 'title') : null;
            $data['orderInfo']['source'] = ["id" => $order->brandSource['id'], "title" => $order->brandSource->source['title']];
            $data['orderInfo']['brand'] = $order->brandSource->brand->only('id', 'title');
            $data['orderInfo']['order_status'] = $order->orderStatus->only('id', 'title');
            $data['orderInfo']['address'] = (count($order->addresses) > 0)
                ? $order->addresses->first()->only('id', 'title')
                : ($order->parentOrder && count($order->parentOrder->addresses) > 0
                    ? $order->parentOrder->addresses->first()->only('id', 'title')
                    : null);
            $data['orderInfo']['city'] = $order->city?->only('id', 'title');
            $data['orderInfo']['phone'] = (count($order->phones) > 0)
                ? $order->phones->first()->phoneTypes->map(function ($phoneType) {
                    return $phoneType->only('id', 'title');
                })
                : ($order->parentOrder && count($order->parentOrder->phones) > 0
                    ? $order->parentOrder->phones->first()->phoneTypes->map(function ($phoneType) {
                        return $phoneType->only('id', 'title');
                    })
                    : []);

            // Format reviews and review score
            $review = \App\Models\Review::where('order_id', $order->id)->with('answers.question')->first();
            $reviewsList = [];
            $starAnswers = [];
            if ($review && $review->answers) {
                foreach ($review->answers as $answer) {
                    $question = $answer->question;
                    if ($question) {
                        $reviewsList[] = [
                            'id' => $answer->id,
                            'answer' => $answer->answer_value,
                            'question' => [
                                'id' => $question->id,
                                'text' => $question->text,
                                'type' => $question->type,
                            ],
                        ];
                        if ($question->type === 'stars') {
                            $starAnswers[] = (float) $answer->answer_value;
                        }
                    }
                }
            }
            $data['orderInfo']['reviews'] = $reviewsList;
            $data['orderInfo']['review_score'] = count($starAnswers) > 0 ? (float) round(array_sum($starAnswers) / count($starAnswers), 2) : null;
        }
        if (isset($request['products']['active'])) {
            // Récupérer les produits du fournisseur avec leurs attributs de variation et types d'attributs
            $filters = HelperFunctions::filterColumns($request['products']['active'], ['title', 'addresse', 'phone', 'products']);
            $orderProducts = Order::find($id);
            $orderDataProducts = [];
            $orderProducts->activeOrderPvas->map(function ($orderPva) use (&$orderDataProducts) {
                // Créer un tableau avec les données de base du produit

                if (!isset($orderDataProducts[$orderPva->id]))
                    $orderDataProducts[$orderPva->id] = [
                        "id" => $orderPva->productVariationAttribute->product->id,
                        "title" => $orderPva->productVariationAttribute->product->title,
                        "reference" => $orderPva->productVariationAttribute->product->reference,
                        "created_at" => $orderPva->created_at,
                        "principalImage" => $orderPva->productVariationAttribute->product->principalImage,
                        "productType" => $orderPva->productVariationAttribute->product->productType->only(['id', 'title']),
                    ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id] = [
                    "id" => $orderPva->id,
                    "price" => $orderPva->price,
                    "discount" => $orderPva->discount,
                    "quantity" => $orderPva->quantity,
                    "order_status_id" => $orderPva->orderStatus->only('id', 'title'),
                ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['offers'] = FilterController::filterselect(new Request(), 'offers', $orderPva->id)['data'];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedOffer'] = null;
                if ($orderPva->offer_variation_id)
                    $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedOffer'] = [
                        "id" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->id,
                        "title" => $orderPva->offerableVariation->childOfferableVariations->first()->offerable->offer->title,
                    ];
                $orderDataProducts[$orderPva->id]['productVariations'][$orderPva->id]['selectedAttributes'] = $orderPva->productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                    // Vérifier si l'attribut a un type
                    if ($childVariationAttribute->attribute->typeAttribute) {
                        // Retourner les données formatées pour chaque attribut de variation
                        return [
                            "id" => $childVariationAttribute->id,
                            "attribute_type" => $childVariationAttribute->attribute->typeAttribute->title,
                            "title" => $childVariationAttribute->attribute->title
                        ];
                    }
                })->filter();
                // Récupérer les variations d'attributs pour chaque produit
                $orderDataProducts[$orderPva->id]['attributes'] = $orderPva->productVariationAttribute->product->productVariationAttributes->flatMap(function ($productVariationAttribute) {
                    return $productVariationAttribute->variationAttribute->childVariationAttributes->map(function ($childVariationAttribute) {
                        // Vérifier si l'attribut a un type
                        if ($childVariationAttribute->attribute->typeAttribute) {
                            // Retourner les données formatées pour chaque attribut de variation
                            return [
                                "id" => $childVariationAttribute->attribute->id,
                                "attribute_type" => $childVariationAttribute->attribute->typeAttribute->title,
                                "title" => $childVariationAttribute->attribute->title
                            ];
                        }
                    }); // Filtrer les valeurs nulles (attributs sans type)
                })->unique()->values(); // Filtrer les valeurs nulles (attributs sans type)
            });
            $productDatas = collect($orderDataProducts)->map(function ($orderDataProduct) {
                $orderDataProduct["productVariations"] = collect($orderDataProduct["productVariations"])->values();
                return $orderDataProduct;
            })->values();
            $data['products']['active'] = HelperFunctions::getPagination(collect($productDatas), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }

        if (isset($request['comments']['active'])) {
            $model = 'App\\Models\\OrderComment';
            $request['comments']['active']['where'] = ['column' => 'order_id', 'value' => $order->id];
            $comments = FilterController::searchs(new Request($request['comments']['active']), $model, ['id', 'title'], false)->map(function ($activeComment) {
                return [
                    "id" => $activeComment->id,
                    "title" => $activeComment->comment?->title ?? 'AfraDelivery',
                    "note" => $activeComment->title,
                    "created_at" => $activeComment->created_at,
                    "postpone" => $activeComment->postpone,
                    "statut" => $activeComment->order_status_id,
                    "employee" => [
                        "id" => $activeComment->accountUser?->id,
                        "name" => trim(($activeComment->accountUser?->user?->firstname ?? '') . " " . ($activeComment->accountUser?->user?->lastname ?? '')),
                        "images" => $activeComment->accountUser?->user?->images
                    ]
                ];
            });
            $filters = HelperFunctions::filterColumns($request['comments']['active'], ['title']);
            $data['comments']['active'] = HelperFunctions::getPagination(collect($comments), $filters['pagination']['per_page'], $filters['pagination']['current_page']);
        }
        return response()->json([
            'statut' => 1,
            'data' => $data
        ]);
    }


    public static function changeStatus(Request $request, $order = null)
    {
        $validator = Validator::make($request->all(), [
            'id' => 'required|exists:comments,id|max:255',
            'postponed' => 'nullable|date',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }
        $comment = Comment::find($request['id']);

        // Calculate comment score based on timing, considering postponed periods
        if (!function_exists('calculateDayBasedScore')) {
            require_once app_path('Helpers/OrderScoreHelper.php');
        }

        $commentScore = calculateDayBasedScore($order->created_at, now(), $order->id);
        $orderStatut = $comment->statut == 2 ? $order->order_status_id : ($comment->new_statut ? $comment->new_statut : $comment->parentComment->current_statut);
        \App\Support\Orders\StatusTransitions::guard($order, $orderStatut ? (int) $orderStatut : null, [
            'comment_id' => $comment->id,
            'source' => 'OrderController::changeStatus',
        ]);

        // the date a "postponed" reason asks for: kept on the history row and returned so update() sets the callback
        $postponed = $comment->postponed && ! empty($request['postponed'])
            ? \Carbon\Carbon::parse($request['postponed'])->setTimezone(config('app.timezone'))
            : null;

        $comment->orders()->attach($order->id, [
            'title' => ($request['title']) ? $request['title'] : $comment->title,
            'order_status_id' => $orderStatut,
            'account_user_id' => getAccountUser()->id,
            'score' => $commentScore,
            'postpone' => $postponed?->toDateTimeString(),
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Recalculate total order score
        // Note: No longer updating orders table score since we removed the column
        // Score is now calculated on-demand from account_user_order_status and order_comment tables

        return ['statut' => $orderStatut, 'is_change' => 0, 'postponed' => $postponed];
    }

    public static function update(Request $requests, $local = 0)
    {
        $productsToActive = [];
        $afraSync = [];
        $validator = Validator::make($requests->except('_method'), [
            // an order can only be changed by the account that owns it (the model scope hides the others)
            '*.id' => ['required', fn ($attribute, $value, $fail) => OrderOwnership::ownsOrder($value) ?: $fail('not exist')],
            '*.comment.id' => 'exists:comments,id|max:255',
            '*.comment.carrier_price' => 'numeric|max:255',
            '*.comment.postponed' => 'date',
            '*.customer_id' => [fn ($attribute, $value, $fail) => OrderOwnership::ownsCustomer($value) ?: $fail('not exist')],
            '*.pickup_id' => ['nullable', fn ($attribute, $value, $fail) => OrderOwnership::ownsPickup($value) ?: $fail('not exist')],
            '*.shipment_id' => ['nullable', fn ($attribute, $value, $fail) => OrderOwnership::ownsShipment($value) ?: $fail('not exist')],
            '*.brand_source_id' => ['nullable', fn ($attribute, $value, $fail) => OrderOwnership::ownsBrandSource($value) ?: $fail('not exist')],
            '*.warehouse_id' => [
                'exists:warehouses,id',
                function ($attribute, $value, $fail) {
                    $account = getAccountUser()->account_id;
                    $warehouse = Warehouse::where(['id' => $value, 'account_id' => $account])->first();
                    if (!$warehouse) {
                        $fail("not exist");
                    }
                },
            ],
            '*.productsToInactive.*' => [
                'required',
                fn ($attribute, $value, $fail) => OrderOwnership::lineBelongsToOrder($value, $requests->input(explode('.', $attribute)[0] . '.id')) ?: $fail('not exist'),
            ],
            '*.productsToUpdate.*.id' => [
                'required',
                fn ($attribute, $value, $fail) => OrderOwnership::lineBelongsToOrder($value, $requests->input(explode('.', $attribute)[0] . '.id')) ?: $fail('not exist'),
            ],
            '*.productsToUpdate.*.quantity' => 'required|numeric',
            '*.productsToActive.*.offers' => 'exists:offers,id|max:255',
            '*.productsToActive.*.attributes' => 'required|exists:attributes,id|max:255',
            '*.productsToActive.*.quantity' => 'required|numeric',
            '*.productsToActive.*.price' => 'numeric',
            '*.productsToActive.*.id' => [
                'required',
                'int',
                function ($attribute, $value, $fail) use ($requests, &$productsToActive) {
                    $account = getAccountUser()->account_id;
                    // Extract index from attribute name
                    $index = str_replace(['*', '.id'], '', $attribute);
                    // Get the ID and title from the request
                    $dataProduct = [
                        'attributes' => $requests->input("{$index}.attributes"),
                        'offers' => $requests->input("{$index}.offers"),
                        'quantity' => $requests->input("{$index}.quantity"),
                        'price' => $requests->input("{$index}.price"),
                        'discount' => $requests->input("{$index}.discount"),
                    ]; // Get ID from request
                    $accountUsers = Account::find($account)->accountUsers->pluck('id')->toArray();
                    $productAttributes = Product::with([
                        'productVariationAttributes.variationAttribute.childVariationAttributes' => function ($vattributes) use ($dataProduct) {
                            $vattributes->whereIn('attribute_id', $dataProduct['attributes']);
                        }
                    ])->where(['id' => $value])->whereIn("account_user_id", $accountUsers)->first();
                    $productAttributes->productVariationAttributes->map(function ($pva) use (&$productsToActive, $dataProduct, $index) {
                        $childs = $pva->variationAttribute->childVariationAttributes->map(function ($child) use (&$productsToActive) {
                            return $child->attribute_id;
                        });
                        $childPvas = $childs->toArray();
                        sort($childPvas);
                        sort($dataProduct['attributes']);
                        if ($childPvas == $dataProduct['attributes']) {
                            $offerIds = collect($dataProduct["offers"])->map(function ($offerId) {
                                $offer = Offer::find($offerId);
                                if (count($offer->productVariationAttributes) > 0) {
                                    $data = $offer->productVariationAttributes->first()->pivot->id;
                                } elseif (count($offer->products) > 0) {
                                    $data = $offer->products->first()->pivot->id;
                                } elseif (count($offer->taxonomies) > 0) {
                                    $data = $offer->taxonomies->first()->pivot->id;
                                } elseif (count($offer->sources) > 0) {
                                    $data = $offer->sources->first()->pivot->id;
                                } elseif (count($offer->brands) > 0) {
                                    $data = $offer->brands->first()->pivot->id;
                                } elseif (count($offer->brandSources) > 0) {
                                    $data = $offer->brandSources->first()->pivot->id;
                                } elseif (count($offer->customers) > 0) {
                                    $data = $offer->customers->first()->pivot->id;
                                } elseif (count($offer->customerTypes) > 0) {
                                    $data = $offer->customerTypes->first()->pivot->id;
                                } elseif (count($offer->cities) > 0) {
                                    $data = $offer->cities->first()->pivot->id;
                                } elseif (count($offer->countries) > 0) {
                                    $data = $offer->countries->first()->pivot->id;
                                } elseif (count($offer->regions) > 0) {
                                    $data = $offer->regions->first()->pivot->id;
                                } elseif (count($offer->sectors) > 0) {
                                    $data = $offer->sectors->first()->pivot->id;
                                }
                                return $data;
                            })->toArray();
                            $productsToActive[$index] = ['id' => $pva->id, 'discount' => $dataProduct['discount'], 'price' => $dataProduct['price'], 'offerables' => $offerIds, 'quantity' => $dataProduct['quantity']];
                        }
                    })->toArray();

                    if (!isset($productsToActive[$index])) {
                        $fail("not Exists");
                    }
                },
            ],

        ]);
        if ($validator->fails()) {
            return response()->json([
                'statut' => 0,
                'data' => $validator->errors(),
            ], 422);
        }
        // Calls to the Afra API are collected here and run once the transaction is committed:
        // an external call must not hold database locks, and must not happen for a change that rolls back.
        $afterCommit = [];

        // One transaction for the whole batch: a failure halfway must not leave an order half updated.
        $orders = DB::transaction(fn () => collect($requests->except('_method'))->map(function ($request, $orderIndex) use ($productsToActive, $local, &$afraSync, &$afterCommit) {
            $comment = null;
            //récupérer la commande a modifier
            $order = Order::find($request['id']);
            $afraService = app(AfraShippingService::class);
            $afraEligible = $local === 0 && (int) $order->account_id === (int) getAccountUser()->account_id
                && AfraShippingService::isAfraOrder($order) && (bool) $order->shipping_code;
            $previousStatus = (int) $order->order_status_id;
            // kept before the update: taking an order out of its pickup may clear its shipping code
            $previousAfraCode = (string) $order->shipping_code;
            $previousFingerprint = $afraEligible ? $afraService->fingerprint($order) : null;
            //vérifier si y a un changement des informations du client
            if (isset($request['customer'])) {
                $request['customer']['id'] = $order->customer_id;
                $customerUpdate = CustomerController::update(new Request([$request['customer']]), $order->customer_id, $isOrder = 1);
                if ($customerUpdate->first()['addresses']) {
                    $order->addresses()->detach();
                    $addresses = collect($customerUpdate->first()['addresses'])->pluck('id');
                    $order->addresses()->attach($addresses);
                }
                if ($customerUpdate->first()['phones']) {
                    $order->phones()->detach();
                    $phones = collect($customerUpdate->first()['phones'])->pluck('id');
                    $order->phones()->attach($phones);
                }
                if ($order->comments->first()) {
                    $commentData = [
                        'id' => $order->comments->first()->id,
                        'title' => "changement d'information du client",
                    ];
                    $comment = OrderController::changeStatus(new Request($commentData), $order);
                }
            }
            if (isset($request['comment'])) {
                $comment = OrderController::changeStatus(new Request(collect($request['comment'])->toArray()), $order);
                // saved with the order below; any other status change clears it (Order::booted)
                if (! empty($comment['postponed'])) {
                    $order->callback_at = $comment['postponed'];
                }
                if ($comment['statut'] == 2) {
                    $request['shipping_code'] = null;
                    $request['pickup_id'] = null;
                } elseif ($comment['statut'] == 3) {
                    $request['pickup_id'] = null;
                }
            }
            //vérifier si le dépôt est changé
            if (isset($request['warehouse_id']) && $request['warehouse_id'] !== $order->warehouse_id) {
                if ($order->comments->first()) {
                    $commentData = [
                        'id' => $order->comments->first()->id,
                        'title' => "changement du dépôt principale",
                    ];
                    $comment = OrderController::changeStatus(new Request($commentData), $order);
                }
            }
            //verifier si y a un changement au niveau des produits avant l'envoi
            if ((isset($request['productsToUpdate']) && isset($request['productsToActive']) && isset($request['productsToInactive'])) && in_array($order->order_status_id, [1, 4])) {
                $commentParent = Comment::where(['current_statut' => $order->order_status_id])->first();
                if ($commentParent) {
                    $commentUpdate = $commentParent->childComments->where('is_change', 3)->first();
                    if ($commentUpdate) {
                        $commentData = [
                            'id' => $commentUpdate->id,
                            'title' => $commentUpdate->title,
                        ];
                        $comment = OrderController::changeStatus(new Request($commentData), $order);
                    }
                }
            }
            $request['note'] = isset($request['note']) ? $request['note'] : null;
            if ($comment !== null) {
                $request['order_status_id'] = $comment['statut'];
            } else {
                $request['order_status_id'] = $order->order_status_id;
            }
            $order_only = collect($request)->only('warehouse_id', 'discount', 'order_status_id', 'city_id', 'brand_source_id', 'payment_type_id', 'payment_method_id', 'pickup_id', 'real_carrier_price', 'shipment_id', 'shipping_code', 'note', 'meta', 'carrier_price', 'sync');

            $order->update($order_only->all());
            if ($comment !== null) {
                $order->activePvas()->update(['order_status_id' => $comment['statut']]);
            }

            // Note: The legacy logic that created "PR" or "CH" specific orders has been removed
            // since returns and exchanges are now handled by createExchange()

            // Note: The legacy logic that created "PR" or "CH" specific orders has been removed
            // since returns and exchanges are now handled by createExchange()

            if (isset($request['productsToInactive'])) {
                foreach ($request['productsToInactive'] as $pvaData) {
                    $orderPva = OrderPva::find($pvaData);
                    $orderPva->update(['order_status_id' => 2]);
                }
            }
            if (isset($request['productsToActive'])) {
                // $productsToActive is keyed "<order index>.productsToActive.<n>": keep this order's lines only
                $ownProducts = array_filter(
                    $productsToActive,
                    fn ($key) => str_starts_with((string) $key, $orderIndex . '.productsToActive.'),
                    ARRAY_FILTER_USE_KEY
                );
                foreach ($ownProducts as $pvaData) {
                    $productVariationAttribute = ProductVariationAttribute::find($pvaData['id']);
                    $initial_price = Product::find($productVariationAttribute->product_id)->price->first()->price;
                    $productPrice = isset($pvaData['price']) ? $pvaData['price'] : $initial_price;
                    $discount = isset($pvaData['discount']) ? $pvaData['discount'] : 0;
                    $realPrice = (Product::find($productVariationAttribute->product_id)->orderPvas) ? Product::find($productVariationAttribute->product_id)->orderPvas->first()->price : 0;
                    $productVariationAttribute->orders()->attach(
                        $order->id,
                        [
                            'quantity' => $pvaData['quantity'],
                            'price' => $productPrice,
                            'realprice' => $realPrice,
                            'initial_price' => $initial_price,
                            'discount' => $discount,
                            'order_status_id' => $order->order_status_id,
                            'account_user_id' => getAccountUser()->id,
                            'created_at' => now(),
                            'updated_at' => now()
                        ]
                    );
                    if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                        VariationOfferableController::store(new Request(['order_id' => $order->id, 'pva' => $productVariationAttribute, 'variations' => $pvaData['offerables']]));
                    }
                }
            }
            if (isset($request['productsToUpdate'])) {
                foreach ($request['productsToUpdate'] as $pvaData) {
                    $orderPva = OrderPva::find($pvaData['id']);
                    if ($pvaData['quantity'] >= $orderPva->quantity) {
                        $orderPva->update([
                            'quantity' => $pvaData['quantity'],
                            'price' => isset($pvaData['price']) ? $pvaData['price'] : $orderPva->price,
                            'discount' => isset($pvaData['discount']) ? $pvaData['discount'] : $orderPva->discount,
                            'account_user_id' => getAccountUser()->id,
                        ]);
                    } else {
                        $productVariationAttribute = ProductVariationAttribute::find($orderPva->product_variation_attribute_id);
                        $CanceledQty = $orderPva->quantity - $pvaData['quantity'];
                        $orderPva->update([
                            'quantity' => $pvaData['quantity'],
                            'price' => isset($pvaData['price']) ? $pvaData['price'] : $orderPva->price,
                            'realprice' => isset($pvaData['realprice']) ? $pvaData['realprice'] : $orderPva->realprice,
                            'discount' => isset($pvaData['discount']) ? $pvaData['discount'] : $orderPva->discount,
                            'account_user_id' => getAccountUser()->id,
                        ]);
                        $productVariationAttribute->orders()->attach(
                            $order->id,
                            [
                                'quantity' => $CanceledQty,
                                'price' => $orderPva->price,
                                'realprice' => $orderPva->realprice,
                                'initial_price' => $orderPva->initial_price,
                                'discount' => $orderPva->discount,
                                'order_status_id' => 2,
                                'account_user_id' => getAccountUser()->id,
                                'created_at' => now(),
                                'updated_at' => now()
                            ]
                        );
                    }
                    if (isset($pvaData['offerables']) && count($pvaData['offerables']) > 0) {
                        VariationOfferableController::store(new Request(['order_pva' => $orderPva, 'variations' => $pvaData['offerables']]));
                    }
                }
            }
            // Note: No longer updating order score in orders table since we removed the column
            // Score is now calculated on-demand from account_user_order_status and order_comment tables

            CompensationableController::edit($order->id);
            if ($afraEligible) {
                $accountUserId = getAccountUser()->id;
                $afterCommit[] = function () use ($order, $afraService, $previousStatus, $previousAfraCode, $previousFingerprint, $accountUserId, &$afraSync) {
                    $updatedOrder = Order::find($order->id);
                    $leftAfraPickup = !AfraShippingService::isAfraOrder($updatedOrder);
                    $cancelled = $previousStatus !== AfraShippingService::CANCELLED_STATUS
                        && (int) $updatedOrder->order_status_id === AfraShippingService::CANCELLED_STATUS;
                    if ($leftAfraPickup || $cancelled) {
                        $afraSync[$order->id]['delete'] = $afraService->cancelAtAfra($updatedOrder, $previousAfraCode, $accountUserId);
                    } elseif ($updatedOrder->shipping_code) {
                        if ($previousFingerprint !== $afraService->fingerprint($updatedOrder)) {
                            $afraSync[$order->id]['update'] = $afraService->update($updatedOrder, $accountUserId);
                        }
                        if ($previousStatus !== AfraShippingService::RETURN_STATUS && (int) $updatedOrder->order_status_id === AfraShippingService::RETURN_STATUS) {
                            $afraSync[$order->id]['return'] = $afraService->requestReturn($updatedOrder, $accountUserId);
                        }
                    }
                };
            }
            if ($local == 1)
                return $order->activeOrderPvas;
            if ($local == 2)
                return $order;
            return $order;
        }));

        // Run the Afra calls after the outermost transaction commits. When update() is itself part of
        // a bigger transaction (pickups, shipments) they wait for that commit, and their outcome
        // is then not part of this response.
        $runAfterCommit = function () use (&$afterCommit) {
            foreach ($afterCommit as $callback) {
                $callback();
            }
        };
        if (DB::transactionLevel() === 0) {
            $runAfterCommit();
        } elseif ($afterCommit !== []) {
            DB::afterCommit($runAfterCommit);
        }

        if ($local == 1 || $local == 2)
            return $orders;
        return response()->json([
            'statut' => 1,
            'data' => $orders,
            'afra_sync' => $afraSync,
        ]);
    }

    public function destroy($id)
    {
        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'statut' => 0,
                'data' => 'not exist'
            ], 404);
        }

        // Only orders that never left the shop can be deleted: pending, abandoned or out of stock.
        // Anything else has stock, pickups, payments or a carrier attached and must follow its status flow.
        if (!in_array((int) $order->order_status_id, [1, 2, 3], true)) {
            return response()->json([
                'statut' => 0,
                'data' => 'Only pending, abandoned or out-of-stock orders can be deleted.',
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->orderPvas()->delete();
            $order->delete();
        });

        return response()->json([
            'statut' => 1,
            'data' => $order,
        ]);
    }

    /**
     * Create a new return or exchange transaction for a given customer.
     * This is the new centralized method for handling all post-sale order adjustments.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    /** The variation of a product made of exactly these attributes (same matching as order creation). */
    private function resolveVariationId($productId, array $attributeIds): ?int
    {
        $wanted = collect($attributeIds)->map(fn ($id) => (int) $id)->sort()->values()->all();
        if (! $productId || ! $wanted) {
            return null;
        }

        $variations = ProductVariationAttribute::with('variationAttribute.childVariationAttributes')
            ->where('product_id', $productId)->get();

        foreach ($variations as $variation) {
            $have = $variation->variationAttribute->childVariationAttributes->pluck('attribute_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            if ($have === $wanted) {
                return $variation->id;
            }
        }

        return null;
    }

    /** The code as is when free for the account, otherwise with -2, -3 ... appended. */
    private function uniqueOrderCode(int $accountId, string $code): string
    {
        $candidate = $code;
        $suffix = 2;
        while (Order::withoutGlobalScopes()->where('account_id', $accountId)->where('code', $candidate)->exists()) {
            $candidate = $code . '-' . $suffix++;
        }

        return $candidate;
    }

    public function createExchange(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // everything referenced must belong to the account of the user
            'customer_id' => ['required', fn ($attribute, $value, $fail) => OrderOwnership::ownsCustomer($value) ?: $fail('not exist')],
            'resolution_type' => 'required|in:refund,exchange',
            'carrier_price' => 'nullable|numeric|min:0',

            // Validate the items being returned
            'items_to_return' => 'required|array|min:1',
            'items_to_return.*.source_order_pva_id' => ['required', fn ($attribute, $value, $fail) => OrderOwnership::ownsOrderLine($value) ?: $fail('not exist')],
            'items_to_return.*.quantity' => 'required|integer|min:1',

            // Validate the new items for an exchange
            'items_to_exchange' => 'required_if:resolution_type,exchange|array|min:1',
            // an exchange item is a variation id, or a product with the attributes the customer picked
            'items_to_exchange.*.pva_id' => ['required_without:items_to_exchange.*.product_id', 'nullable', fn ($attribute, $value, $fail) => $value === null || OrderOwnership::ownsProductVariation($value) ?: $fail('not exist')],
            'items_to_exchange.*.product_id' => ['required_without:items_to_exchange.*.pva_id', 'nullable', fn ($attribute, $value, $fail) => $value === null || \App\Support\ProductOwnership::owns($value) ?: $fail('not exist')],
            'items_to_exchange.*.attributes' => 'required_with:items_to_exchange.*.product_id|array',
            'items_to_exchange.*.attributes.*' => 'integer|exists:attributes,id',
            'items_to_exchange.*.quantity' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json(['statut' => 0, 'data' => $validator->errors()], 422);
        }

        $exchangeItems = [];
        foreach ((array) $request->input('items_to_exchange', []) as $index => $item) {
            $pvaId = $item['pva_id'] ?? $this->resolveVariationId($item['product_id'] ?? null, $item['attributes'] ?? []);
            if (! $pvaId) {
                return response()->json(['statut' => 0, 'data' => ["items_to_exchange.{$index}.attributes" => ['no variation matches these attributes']]], 422);
            }
            $exchangeItems[] = ['pva_id' => (int) $pvaId, 'quantity' => (int) $item['quantity']];
        }

        try {
            $result = DB::transaction(function () use ($request, $exchangeItems) {
                $accountUser = getAccountUser();
                $accountId = $accountUser->account_id;

                // Fetch the original order to inherit required fields like payment_type_id
                $firstOriginalPva = OrderPva::find($request->items_to_return[0]['source_order_pva_id']);
                $originalOrder = $firstOriginalPva ? Order::find($firstOriginalPva->order_id) : null;

                // 1. Create the main "return" order. This acts as the master record for the transaction.
                // We set order_status_id to 6 (In Transit) because the returned items are moving back to the warehouse.
                $baseCode = $originalOrder ? $originalOrder->code : DefaultCodeController::getAccountCode('return', $accountId);

                $returnOrder = Order::create([
                    'account_id' => $accountId,
                    'customer_id' => $request->customer_id,
                    'order_id' => $originalOrder ? $originalOrder->id : null, // Link to original order
                    'order_status_id' => 6, // 6 = In Transit
                    'type' => 'return', // This is the crucial part!
                    'code' => $this->uniqueOrderCode($accountId, $baseCode . '-RT'),
                    'warehouse_id' => $originalOrder ? $originalOrder->warehouse_id : Warehouse::where('account_id', $accountId)->first()->id ?? 1,
                    'payment_type_id' => $originalOrder ? $originalOrder->payment_type_id : 1,
                    'payment_method_id' => $originalOrder ? $originalOrder->payment_method_id : 1,
                    'brand_source_id' => $originalOrder ? $originalOrder->brand_source_id : 1,
                    'adresse' => $originalOrder ? $originalOrder->adresse : null,
                    'city_id' => $originalOrder ? $originalOrder->city_id : null,
                ]);

                // 2. Add the items being returned to this new order.
                foreach ($request->items_to_return as $item) {
                    $originalPva = OrderPva::find($item['source_order_pva_id']);
                    if (!$originalPva)
                        continue;

                    OrderPva::create([
                        'order_id' => $returnOrder->id,
                        'product_variation_attribute_id' => $originalPva->product_variation_attribute_id,
                        'source_order_pva_id' => $item['source_order_pva_id'], // Link to original item
                        'quantity' => abs($item['quantity']), // Positive quantity because items are physically coming back
                        'price' => 0, // Set price to 0 DH for the return
                        'order_status_id' => 6, // 6 = In Transit
                        'account_user_id' => $accountUser->id,
                    ]);
                }

                $exchangeOrder = null;
                if ($request->resolution_type === 'exchange') {
                    // 3. If it's an exchange, create a new "sale" order for the outgoing items.
                    // This keeps financials clean: a return is a credit, a new sale is a debit.
                    $exchangeOrder = Order::create([
                        'account_id' => $accountId,
                        'customer_id' => $request->customer_id,
                        'order_id' => $originalOrder ? $originalOrder->id : null, // Link Exchange directly to original order
                        'order_status_id' => 1, // Example: "Pending"
                        'type' => 'sale', // It's a new sale to the customer
                        'code' => $this->uniqueOrderCode($accountId, $baseCode . '-EX'),
                        'carrier_price' => $request->carrier_price ?? 0,
                        'warehouse_id' => $returnOrder->warehouse_id,
                        'payment_type_id' => $returnOrder->payment_type_id,
                        'payment_method_id' => $returnOrder->payment_method_id,
                        'brand_source_id' => $returnOrder->brand_source_id,
                        'adresse' => $returnOrder->adresse,
                        'city_id' => $returnOrder->city_id,
                    ]);

                    $shippingPriceTotal = $request->carrier_price ?? 0;

                    foreach ($exchangeItems as $index => $item) {
                        // Apply the shipping price to the first item so the total matches shipping_price
                        $itemPrice = ($index === 0 && $shippingPriceTotal > 0) ? ($shippingPriceTotal / abs($item['quantity'])) : 0;

                        OrderPva::create([
                            'order_id' => $exchangeOrder->id,
                            'product_variation_attribute_id' => $item['pva_id'],
                            'quantity' => abs($item['quantity']), // Positive quantity
                            'price' => $itemPrice, // Set so the total of the order equals shipping_price
                            'order_status_id' => 1, // "Pending Shipment"
                            'account_user_id' => $accountUser->id,
                        ]);
                    }

                    // 4. Update the original order status to "Delivered" (7) because an exchange implies the courier 
                    // successfully reached the customer to make the swap.
                    if ($originalOrder && $originalOrder->order_status_id != 7) {
                        $deliveredComment = \App\Models\Comment::where('new_statut', 7)->first();
                        if ($deliveredComment) {
                            $updateRequest = new Request([
                                [
                                    'id' => $originalOrder->id,
                                    'comment' => [
                                        'id' => $deliveredComment->id,
                                        'title' => 'Exchange Processed'
                                    ]
                                ]
                            ]);
                            self::update($updateRequest, 1);
                        } else {
                            // Fallback if comment is not found for some reason
                            $originalOrder->update(['order_status_id' => 7]);
                        }
                    }
                }

                return [
                    'return_order_id' => $returnOrder->id,
                    'exchange_order_id' => $exchangeOrder ? $exchangeOrder->id : null,
                ];
            });

            return response()->json([
                'statut' => 1,
                'data' => $result,
                'message' => 'Return/exchange processed successfully.'
            ]);

        } catch (\Throwable $e) {
            Log::error('Return/Exchange creation failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['statut' => 0, 'data' => $e->getMessage()], 500);
        }
    }

    /**
     * Swaps the delivery details from an in-transit order to a new confirmed order.
     * This is used when a customer cancels an order that is already out for delivery,
     * and a new customer order for the same item can take its place.
     *
     * @param int $inTransitOrderId The ID of the order that is currently 'in transit' (e.g., status 6).
     * @param int $newOrderId The ID of the new, confirmed order that will take over the delivery (e.g., status 4).
     * @return \Illuminate\Http\JsonResponse
     */
    public function swapDelivery($inTransitOrderId, $newOrderId)
    {
        // Basic validation to ensure we have two different, valid IDs.
        if ($inTransitOrderId == $newOrderId) {
            return response()->json(['statut' => 0, 'data' => 'The two order IDs must be different.'], 400);
        }

        try {
            $response = DB::transaction(function () use ($inTransitOrderId, $newOrderId) {
                // Lock the two order records to prevent any other processes from changing them during the swap.
                $inTransitOrder = Order::lockForUpdate()->find($inTransitOrderId);
                $newOrder = Order::lockForUpdate()->find($newOrderId);

                if (!$inTransitOrder || !$newOrder) {
                    throw new \Exception('One or both orders could not be found.');
                }

                // --- Business Logic Validations ---
                // 1. The original order MUST be "in transit" (we assume status 6) and have a pickup_id.
                if ($inTransitOrder->order_status_id != 6) {
                    throw new \Exception("The first order ({$inTransitOrder->code}) is not in transit.");
                }
                if (is_null($inTransitOrder->pickup_id)) {
                    throw new \Exception("The first order ({$inTransitOrder->code}) does not have a pickup ID and cannot be swapped.");
                }

                // 2. The new order should be in a confirmed but not yet shipped state (we assume status 4).
                if ($newOrder->order_status_id != 4) {
                    throw new \Exception("The new order ({$newOrder->code}) is not in a confirmed state ready for shipping.");
                }
                if (!is_null($newOrder->pickup_id)) {
                    throw new \Exception("The new order ({$newOrder->code}) already has a pickup assigned and cannot be swapped.");
                }

                // --- Execute the Swap ---
                // 1. Copy delivery details to the new order.
                $deliveryDetails = [
                    'pickup_id' => $inTransitOrder->pickup_id,
                    'shipping_code' => $inTransitOrder->shipping_code,
                    'carrier_price' => $inTransitOrder->carrier_price,
                    'real_carrier_price' => $inTransitOrder->real_carrier_price,
                    // Copy any other relevant delivery/carrier fields here.
                ];
                $newOrder->update($deliveryDetails);

                // 2. Clear delivery details from the old order.
                $inTransitOrder->update([
                    'pickup_id' => null,
                    'shipping_code' => null,
                    // Set other delivery fields to null as needed.
                ]);

                // 3. Link the new order to the original one for tracking.
                $newOrder->update(['order_id' => $inTransitOrder->id]);

                // 4. Add notes for historical tracking.
                $inTransitOrder->update(['note' => "Delivery swapped to order {$newOrder->code}."]);
                $newOrder->update(['note' => "Took over delivery from canceled order {$inTransitOrder->code}."]);


                // 5. Swap the statuses.
                $newOrder->update(['order_status_id' => 6]); // New order is now "in transit"
                $inTransitOrder->update(['order_status_id' => 3]); // Old order is now "Canceled" (or your preferred status)

                return [
                    'statut' => 1,
                    'data' => [
                        'original_order' => $inTransitOrder->id,
                        'new_order' => $newOrder->id,
                    ],
                    'message' => 'Delivery has been successfully swapped.'
                ];
            });

            // The Afra parcel now carries the new order: send Afra its products and amount.
            $swapped = Order::find($newOrderId);
            if ($swapped?->shipping_code && AfraShippingService::isAfraOrder($swapped)) {
                $response['afra_sync'][$swapped->id]['update'] = app(AfraShippingService::class)->update($swapped, getAccountUser()->id);
            }

            return response()->json($response);

        } catch (\Throwable $e) {
            Log::error('Order delivery swap failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'statut' => 0,
                'data' => $e->getMessage(),
            ], 422);
        }
    }

    public function syncSheet(Request $request, $id)
    {
        $order = Order::where('account_id', getAccountUser()->account_id)->find($id);
        if (!$order) {
            return response()->json([
                'statut' => 0,
                'message' => 'Order not found'
            ], 404);
        }

        try {
            $accountUser = getAccountUser();
            app(GoogleSheetsService::class)->appendOrderStatusRow(
                $order,
                null,
                $accountUser,
                'Nouvelle commande créée'
            );

            $order->comments()->syncWithoutDetaching([
                44 => [
                    'title' => 'Sync with Google Sheets',
                    'order_status_id' => $order->order_status_id,
                    'account_user_id' => $accountUser->id,
                    'score' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ]);

            $order->sync = true;
            $order->save();

            return response()->json([
                'statut' => 1,
                'message' => 'Order synced successfully to Google Sheet',
                'data' => [
                    'sync' => true
                ]
            ]);
        } catch (\Throwable $e) {
            $order->sync = false;
            $order->save();
            Log::warning('Google Sheets sync failed for order ' . $order->id . ': ' . $e->getMessage());

            return response()->json([
                'statut' => 0,
                'message' => 'Google Sheets sync failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
