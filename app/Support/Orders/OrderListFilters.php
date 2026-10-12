<?php

namespace App\Support\Orders;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
            $search = trim($request['search']);
            if ($search !== '') {
                self::applySearch($query, $search);
            }
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

    /** Optimized order search across code, shipping_code, customer name, phone, and address. */
    public static function applySearch(Builder $query, string $search): void
    {
        $hasLetters = (bool) preg_match('/[a-zA-Z\x{0600}-\x{06FF}]/u', $search);
        $hasDigits = (bool) preg_match('/[0-9]/', $search);
        $cleanDigits = preg_replace('/[^0-9]/', '', $search);
        $isPurePhone = ! $hasLetters && strlen($cleanDigits) >= 7;

        $customerIds = [];

        // 1. Customer names: match unless search is purely a 7+ digit phone number
        if (! $isPurePhone) {
            $nameIds = DB::table('customers')
                ->where('name', 'like', "%$search%")
                ->whereNull('deleted_at')
                ->pluck('id')
                ->toArray();
            if (! empty($nameIds)) {
                $customerIds = array_merge($customerIds, $nameIds);
            }
        }

        // 2. Phones: match if search contains digits and no letters
        if ($hasDigits && ! $hasLetters) {
            $phoneQuery = DB::table('phones')
                ->join('phoneables', function ($join) {
                    $join->on('phones.id', '=', 'phoneables.phone_id')
                        ->where('phoneables.phoneable_type', '=', 'App\\Models\\Customer');
                })
                ->whereNull('phones.deleted_at')
                ->where(function ($q) use ($search, $cleanDigits) {
                    $q->where('phones.title', 'like', "%$search%");
                    if ($cleanDigits !== $search && strlen($cleanDigits) >= 6) {
                        $q->orWhere('phones.title', 'like', "%$cleanDigits%");
                    }
                    if (str_starts_with($cleanDigits, '212') && strlen($cleanDigits) >= 11) {
                        $local = '0' . substr($cleanDigits, 3);
                        $q->orWhere('phones.title', 'like', "%$local%");
                    } elseif (str_starts_with($cleanDigits, '0') && strlen($cleanDigits) >= 10) {
                        $withoutZero = substr($cleanDigits, 1);
                        $q->orWhere('phones.title', 'like', "%$withoutZero%");
                    }
                });

            $phoneCustomerIds = $phoneQuery->pluck('phoneables.phoneable_id')->toArray();
            if (! empty($phoneCustomerIds)) {
                $customerIds = array_merge($customerIds, $phoneCustomerIds);
            }
        }

        // 3. Addresses: match unless search is purely a 7+ digit phone number
        if (! $isPurePhone) {
            $addrIds = DB::table('addresses')
                ->join('addressables', function ($join) {
                    $join->on('addresses.id', '=', 'addressables.address_id')
                        ->where('addressables.addressable_type', '=', 'App\\Models\\Customer');
                })
                ->where('addresses.title', 'like', "%$search%")
                ->whereNull('addresses.deleted_at')
                ->pluck('addressables.addressable_id')
                ->toArray();
            if (! empty($addrIds)) {
                $customerIds = array_merge($customerIds, $addrIds);
            }
        }

        $customerIds = array_values(array_unique($customerIds));

        $query->where(function ($q) use ($search, $customerIds) {
            $q->where('orders.code', 'like', "%$search%")
              ->orWhere('orders.shipping_code', 'like', "%$search%");

            if (! empty($customerIds)) {
                $q->orWhereIn('orders.customer_id', $customerIds);
            }
        });
    }
}
