<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters of GET /api/orders, shared with the workspace counters so a tab badge counts the same
 * orders the list shows. `$request` is the query string as an array.
 */
final class OrderListFilters
{
    /** filter key => [type, column or relation path, column of the relation] */
    private const FILTER_MAP = [
        'products' => ['relation', 'activeOrderPvas.productVariationAttribute.product', 'id'],
        'warehouses' => ['column', 'warehouse_id'],
        'categories' => ['relation', 'activeOrderPvas.productVariationAttribute.product.taxonomies', 'id'],
        'brands' => ['relation', 'brandSource.brand', 'id'],
        'sources' => ['relation', 'brandSource.source', 'id'],
        'customer_types' => ['relation', 'customer', 'customer_type_id'],
        'cities' => ['relation', 'customer.addresses', 'city_id'],
        'regions' => ['relation', 'customer.addresses.city.region', 'id'],
        'countries' => ['relation', 'customer.addresses.city.region.country', 'id'],
        'status' => ['column', 'order_status_id'],
        'types' => ['column', 'type'],
        'sectors' => ['column', 'sector_id'],
        'carriers' => ['relation', 'pickup.carrier', 'id'],
    ];

    /** The account's orders, filtered as the request asks. */
    public static function query(array $request): Builder
    {
        $query = Order::where('orders.account_id', getAccountUser()->account_id);

        $hasReviews = null;
        if (isset($request['has_reviews'])) {
            $hasReviews = filter_var($request['has_reviews'], FILTER_VALIDATE_BOOLEAN);
        } elseif (isset($request['filter']['has_reviews'])) {
            $hasReviews = filter_var($request['filter']['has_reviews'], FILTER_VALIDATE_BOOLEAN);
        }
        if ($hasReviews !== null) {
            $hasReviews ? $query->whereHas('review') : $query->whereDoesntHave('review');
        }

        foreach (self::FILTER_MAP as $key => $info) {
            if (! empty($request[$key]) && is_array($request[$key])) {
                if ($info[0] === 'column') {
                    $query->whereIn($info[1], $request[$key]);
                } else {
                    [, $relation, $column] = $info;
                    $query->whereHas($relation, fn ($q) => $q->whereIn($column, $request[$key]));
                }
            }
        }

        // code, tracking number, customer name, phone or address
        if (! empty($request['search']) && is_string($request['search'])) {
            $search = $request['search'];
            $query->where(function ($query) use ($search) {
                $query->where('code', 'like', "%$search%")->orWhere('shipping_code', 'like', "%$search%")
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', "%$search%")
                            ->orWhereHas('phones', fn ($q2) => $q2->where('title', 'like', "%$search%"))
                            ->orWhereHas('addresses', fn ($q3) => $q3->where('title', 'like', "%$search%"));
                    });
            });
        }

        if (! empty($request['startDate'])) {
            $query->where('orders.created_at', '>=', $request['startDate'] . ' 00:00:00');
        }
        if (! empty($request['endDate'])) {
            $query->where('orders.created_at', '<=', $request['endDate'] . ' 23:59:59');
        }

        if (! empty($request['assigned_to']) && is_array($request['assigned_to'])) {
            self::applyAssignees($query, $request['assigned_to']);
        }

        return $query;
    }

    /** Orders assigned to any of the given agents: account_user ids, `me` (the current agent) or `none`. */
    public static function applyAssignees(Builder $query, array $values): Builder
    {
        $ids = [];
        $none = false;
        foreach ($values as $value) {
            if ($value === 'none') {
                $none = true;
            } elseif ($value === 'me') {
                $ids[] = (int) getAccountUser()->id;
            } elseif (is_numeric($value)) {
                $ids[] = (int) $value;
            }
        }

        return $query->where(function ($q) use ($ids, $none) {
            if ($ids) {
                $q->whereIn('orders.assigned_to', $ids);
            }
            if ($none) {
                $q->orWhereNull('orders.assigned_to');
            }
            if (! $ids && ! $none) {
                $q->whereRaw('1 = 0');
            }
        });
    }

    /** min_age_days / max_age_days of the request, as the queue options. */
    public static function ageOptions(array $request): array
    {
        $int = fn ($value) => is_numeric($value) ? max(0, (int) $value) : null;

        return [
            'min_age_days' => $int($request['min_age_days'] ?? null),
            'max_age_days' => $int($request['max_age_days'] ?? null),
        ];
    }
}
