<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Customer;
use App\Support\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class OrderDuplicateService
{
    /**
     * Eligible statuses for soft deletion of duplicates.
     * Orders that never left the warehouse/courier.
     */
    public const DELETABLE_STATUSES = [
        OrderStatus::PENDING,      // 1
        OrderStatus::ABANDONED,    // 2
        OrderStatus::OUT_OF_STOCK, // 3
        OrderStatus::CANCELLED,    // 8
    ];

    /**
     * Priority rank for deciding which order to keep (lower number = higher priority).
     * Confirmed, delivered, paid orders must always be preserved.
     */
    private const STATUS_KEEP_PRIORITY = [
        OrderStatus::PAID           => 1,
        OrderStatus::DELIVERED      => 2,
        OrderStatus::IN_DELIVERY    => 3,
        OrderStatus::IN_PREPARATION => 4,
        OrderStatus::CONFIRMED      => 5,
        OrderStatus::PENDING        => 6,
        OrderStatus::OUT_OF_STOCK   => 7,
        OrderStatus::ABANDONED      => 8,
        OrderStatus::CANCELLED      => 9,
        OrderStatus::RETURNED       => 10,
        OrderStatus::IN_TROUBLE     => 11,
    ];

    /**
     * Get paginated duplicate order groups for an account.
     */
    public function getDuplicates(int $accountId, array $filters = []): array
    {
        $windowHours = $filters['window_hours'] ?? 48;
        $isSameDay = $windowHours === 'same_day';
        $windowHoursInt = is_numeric($windowHours) ? (int) $windowHours : 48;

        $startDate = !empty($filters['start_date']) ? Carbon::parse($filters['start_date'])->startOfDay() : null;
        $endDate = !empty($filters['end_date']) ? Carbon::parse($filters['end_date'])->endOfDay() : null;
        $search = trim($filters['search'] ?? '');

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 10)));

        // 1. Build subquery to find customer IDs who have duplicate orders
        $query = DB::table('orders as o1')
            ->join('orders as o2', function ($join) use ($isSameDay, $windowHoursInt) {
                $join->on('o1.customer_id', '=', 'o2.customer_id')
                    ->whereColumn('o1.id', '<>', 'o2.id')
                    ->whereColumn('o1.account_id', '=', 'o2.account_id')
                    ->whereColumn('o2.created_at', '>=', 'o1.created_at');

                if ($isSameDay) {
                    $join->whereRaw('DATE(o2.created_at) = DATE(o1.created_at)');
                } else {
                    $join->whereRaw("o2.created_at <= DATE_ADD(o1.created_at, INTERVAL {$windowHoursInt} HOUR)");
                }
            })
            ->where('o1.account_id', $accountId)
            ->whereNull('o1.deleted_at')
            ->whereNull('o2.deleted_at');

        if ($startDate) {
            $query->where('o1.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('o1.created_at', '<=', $endDate);
        }

        // Apply text search on customer name, order code, or phone number
        if ($search !== '') {
            $query->join('customers as c', 'o1.customer_id', '=', 'c.id')
                ->leftJoin('phoneables as p', function ($join) {
                    $join->on('p.phoneable_id', '=', 'o1.id')
                        ->where('p.phoneable_type', '=', Order::class);
                })
                ->leftJoin('phones as ph', 'p.phone_id', '=', 'ph.id')
                ->where(function ($q) use ($search) {
                    $q->where('c.name', 'like', "%{$search}%")
                        ->orWhere('o1.code', 'like', "%{$search}%")
                        ->orWhere('ph.title', 'like', "%{$search}%");
                });
        }

        // Distinct customer IDs with duplicates
        $totalGroups = (clone $query)->distinct('o1.customer_id')->count('o1.customer_id');

        $customerIds = (clone $query)
            ->select('o1.customer_id')
            ->distinct()
            ->orderByDesc('o1.customer_id')
            ->forPage($page, $perPage)
            ->pluck('customer_id');

        if ($customerIds->isEmpty()) {
            return [
                'groups' => [],
                'pagination' => [
                    'total_groups' => 0,
                    'total_pages' => 0,
                    'current_page' => $page,
                    'per_page' => $perPage,
                ],
                'summary' => [
                    'total_groups' => 0,
                    'total_duplicate_orders' => 0,
                    'deletable_orders' => 0,
                ],
            ];
        }

        // 2. Fetch all candidate orders for these customers with relations
        $rawOrders = Order::where('account_id', $accountId)
            ->whereIn('customer_id', $customerIds)
            ->whereNull('deleted_at')
            ->with([
                'customer.phones',
                'phones',
                'orderStatus',
                'city',
                'orderPvas.productVariationAttribute.product',
                'orderPvas.productVariationAttribute.variationAttribute.childVariationAttributes.attribute',
            ])
            ->orderBy('customer_id')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // 3. Group and filter orders to only include those in duplicate clusters
        $groups = [];
        $totalDuplicateOrdersCount = 0;
        $totalDeletableCount = 0;

        $ordersByCustomer = $rawOrders->groupBy('customer_id');

        foreach ($customerIds as $cId) {
            $customerOrders = $ordersByCustomer->get($cId, collect());
            if ($customerOrders->count() < 2) {
                continue;
            }

            // Keep orders that are within the window of at least one other order
            $clusteredOrders = $customerOrders->filter(function ($order) use ($customerOrders, $isSameDay, $windowHoursInt) {
                $orderTime = Carbon::parse($order->created_at);
                foreach ($customerOrders as $other) {
                    if ($other->id === $order->id) {
                        continue;
                    }
                    $otherTime = Carbon::parse($other->created_at);
                    if ($isSameDay) {
                        if ($orderTime->isSameDay($otherTime)) {
                            return true;
                        }
                    } else {
                        $diffInHours = abs($orderTime->diffInHours($otherTime, false));
                        if ($diffInHours <= $windowHoursInt) {
                            return true;
                        }
                    }
                }
                return false;
            })->values();

            if ($clusteredOrders->count() < 2) {
                continue;
            }

            // Determine recommended order to keep
            // Lowest priority number = highest status priority; tie-breaker = earliest created_at
            $bestOrder = $clusteredOrders->sortBy(function ($order) {
                $priority = self::STATUS_KEEP_PRIORITY[$order->order_status_id] ?? 99;
                $timestamp = Carbon::parse($order->created_at)->timestamp;
                return sprintf('%03d-%012d-%08d', $priority, $timestamp, $order->id);
            })->first();

            $recommendedKeepId = $bestOrder ? $bestOrder->id : $clusteredOrders->first()->id;

            $firstOrder = $clusteredOrders->first();
            $customer = $firstOrder->customer;
            $customerPhone = $firstOrder->phones->first()?->title
                ?? $customer?->phones->first()?->title
                ?? 'aucun';

            $formattedOrders = [];
            foreach ($clusteredOrders as $order) {
                $isRecommendedKeep = ($order->id === $recommendedKeepId);

                // Check if eligible for deletion
                $isDeletableStatus = in_array((int) $order->order_status_id, self::DELETABLE_STATUSES, true);
                $hasNoShipment = empty($order->shipment_id);
                $hasNoPickup = empty($order->pickup_id);
                $canDelete = $isDeletableStatus && $hasNoShipment && $hasNoPickup;

                // Recommended action
                $recommendedAction = $isRecommendedKeep ? 'keep' : ($canDelete ? 'delete' : 'keep_protected');

                // Compute order total & items
                $totalAmount = 0;
                $items = [];
                foreach ($order->orderPvas as $pva) {
                    $totalAmount += ($pva->price * $pva->quantity);
                    $product = $pva->productVariationAttribute?->product;
                    $variation = $pva->productVariationAttribute;

                    $variantDesc = '';
                    if ($variation && $variation->variationAttribute) {
                        $variantDesc = collect($variation->variationAttribute->childVariationAttributes ?? [])
                            ->map(fn ($c) => $c->attribute?->title)
                            ->filter()
                            ->implode(' / ');
                    }

                    $items[] = [
                        'id' => $pva->id,
                        'product_name' => $product?->name ?? 'Produit',
                        'variation' => $variantDesc,
                        'quantity' => (int) $pva->quantity,
                        'price' => (float) $pva->price,
                    ];
                }

                if ($canDelete && !$isRecommendedKeep) {
                    $totalDeletableCount++;
                }
                $totalDuplicateOrdersCount++;

                $formattedOrders[] = [
                    'id' => $order->id,
                    'code' => $order->code,
                    'created_at' => $order->created_at?->toIso8601String(),
                    'created_at_formatted' => $order->created_at?->format('d/m/Y H:i'),
                    'order_status_id' => $order->order_status_id,
                    'status_title' => $order->orderStatus?->title ?? 'Inconnu',
                    'status_color' => $this->getStatusColor($order->order_status_id),
                    'total' => $totalAmount,
                    'carrier_price' => (float) ($order->carrier_price ?? 0),
                    'city_name' => $order->city?->name ?? null,
                    'items' => $items,
                    'items_count' => count($items),
                    'can_delete' => $canDelete,
                    'is_recommended_keep' => $isRecommendedKeep,
                    'recommended_action' => $recommendedAction,
                    'protection_reason' => $this->getProtectionReason($order, $canDelete),
                ];
            }

            $groups[] = [
                'customer_id' => $cId,
                'customer_name' => $customer?->name ?? 'Client #' . $cId,
                'phone' => $customerPhone,
                'orders_count' => count($formattedOrders),
                'recommended_keep_id' => $recommendedKeepId,
                'orders' => $formattedOrders,
            ];
        }

        $totalPages = (int) ceil($totalGroups / $perPage);

        return [
            'groups' => $groups,
            'pagination' => [
                'total_groups' => $totalGroups,
                'total_pages' => $totalPages,
                'current_page' => $page,
                'per_page' => $perPage,
            ],
            'summary' => [
                'total_groups' => $totalGroups,
                'page_duplicate_orders' => $totalDuplicateOrdersCount,
                'page_deletable_orders' => $totalDeletableCount,
            ],
        ];
    }

    /**
     * Safely bulk soft-delete duplicate orders.
     */
    public function bulkDelete(int $accountId, array $orderIds): array
    {
        $orderIds = array_unique(array_filter(array_map('intval', $orderIds)));

        if (empty($orderIds)) {
            return [
                'deleted_count' => 0,
                'failed_count' => 0,
                'errors' => [],
            ];
        }

        $orders = Order::where('account_id', $accountId)
            ->whereIn('id', $orderIds)
            ->whereNull('deleted_at')
            ->get();

        $deletedCount = 0;
        $failedCount = 0;
        $errors = [];

        foreach ($orders as $order) {
            // Safety validation
            if (!in_array((int) $order->order_status_id, self::DELETABLE_STATUSES, true)) {
                $failedCount++;
                $errors[] = [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'reason' => "Le statut de la commande ({$order->order_status_id}) ne permet pas la suppression.",
                ];
                continue;
            }

            if (!empty($order->shipment_id) || !empty($order->pickup_id)) {
                $failedCount++;
                $errors[] = [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'reason' => 'La commande a une expédition ou un ramassage rattaché.',
                ];
                continue;
            }

            try {
                DB::transaction(function () use ($order) {
                    $order->orderPvas()->delete();
                    $order->delete();
                });
                $deletedCount++;
            } catch (\Throwable $e) {
                $failedCount++;
                $errors[] = [
                    'order_id' => $order->id,
                    'code' => $order->code,
                    'reason' => 'Erreur technique lors de la suppression : ' . $e->getMessage(),
                ];
            }
        }

        return [
            'deleted_count' => $deletedCount,
            'failed_count' => $failedCount,
            'errors' => $errors,
        ];
    }

    private function getStatusColor(?int $statusId): string
    {
        return match ($statusId) {
            OrderStatus::PENDING => 'warning',
            OrderStatus::CONFIRMED => 'info',
            OrderStatus::IN_PREPARATION => 'primary',
            OrderStatus::IN_DELIVERY => 'secondary',
            OrderStatus::DELIVERED => 'success',
            OrderStatus::PAID => 'success',
            OrderStatus::CANCELLED => 'error',
            OrderStatus::ABANDONED => 'default',
            OrderStatus::OUT_OF_STOCK => 'error',
            OrderStatus::RETURNED => 'error',
            default => 'default',
        };
    }

    private function getProtectionReason(Order $order, bool $canDelete): ?string
    {
        if ($canDelete) {
            return null;
        }

        if (!empty($order->shipment_id)) {
            return 'Expédition active associée';
        }
        if (!empty($order->pickup_id)) {
            return 'Bon de ramassage associé';
        }
        if (!in_array((int) $order->order_status_id, self::DELETABLE_STATUSES, true)) {
            return 'Statut protégé (' . ($order->orderStatus?->title ?? 'Avancé') . ')';
        }

        return 'Commande protégée';
    }
}
