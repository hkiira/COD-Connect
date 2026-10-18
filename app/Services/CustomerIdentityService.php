<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerCall;
use App\Models\Order;
use App\Models\Phone;
use Illuminate\Support\Facades\DB;

class CustomerIdentityService
{
    /**
     * Generic placeholder names that should never overwrite a real customer name.
     */
    private const PLACEHOLDER_NAMES = [
        '',
        '-',
        'client',
        'client website',
        'client web',
        'unknown',
        'inconnu',
        'aucun',
    ];

    /**
     * Normalize a phone number to canonical Moroccan format (06XXXXXXXX, 07XXXXXXXX, 05XXXXXXXX, 08XXXXXXXX)
     * or clean digits for international numbers.
     */
    public static function normalizePhone(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }

        $trimmed = trim($raw);
        if ($trimmed === '' || strtolower($trimmed) === 'aucun') {
            return '';
        }

        return formatPhoneNumber($trimmed);
    }

    /**
     * Check whether a customer name is empty or a generic placeholder.
     */
    public static function isPlaceholderName(?string $name): bool
    {
        if ($name === null) {
            return true;
        }

        $normalized = mb_strtolower(trim($name));

        return in_array($normalized, self::PLACEHOLDER_NAMES, true);
    }

    /**
     * Choose the best customer name between an existing name and an incoming name.
     * Never overwrites a real name with a placeholder ('client', 'Client WebSite', etc.).
     */
    public static function chooseBestName(?string $existingName, ?string $incomingName): string
    {
        $incomingTrimmed = trim((string) $incomingName);
        $existingTrimmed = trim((string) $existingName);

        if (!self::isPlaceholderName($incomingTrimmed)) {
            return $incomingTrimmed;
        }

        if (!self::isPlaceholderName($existingTrimmed)) {
            return $existingTrimmed;
        }

        return $incomingTrimmed !== '' ? $incomingTrimmed : ($existingTrimmed !== '' ? $existingTrimmed : 'Client');
    }

    /**
     * Batch-compute COD customer metrics for a list of customer IDs in a single query.
     *
     * @param array<int> $customerIds
     * @return array<int, array{
     *   delivered_count: int,
     *   returned_count: int,
     *   pending_count: int,
     *   total_orders: int,
     *   lifetime_value: float,
     *   average_order_value: float,
     *   delivery_rate: float|null,
     *   return_rate: float,
     *   tier: string,
     *   trust_level: string
     * }>
     */
    public static function batchCustomerMetrics(array $customerIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $customerIds))));
        if (empty($ids)) {
            return [];
        }

        $lineTotalsSub = DB::table('order_pva')
            ->selectRaw('order_id, COALESCE(SUM(price * quantity), 0) as line_total')
            ->whereNull('deleted_at')
            ->whereNotIn('order_status_id', [2, 3])
            ->groupBy('order_id');

        $rows = DB::table('orders')
            ->leftJoinSub($lineTotalsSub, 'lt', function ($join) {
                $join->on('orders.id', '=', 'lt.order_id');
            })
            ->whereIn('orders.customer_id', $ids)
            ->where('orders.type', 'sale')
            ->whereNull('orders.deleted_at')
            ->groupBy('orders.customer_id')
            ->selectRaw('
                orders.customer_id,
                COUNT(orders.id) as total_orders,
                SUM(CASE WHEN orders.order_status_id IN (7, 10) THEN 1 ELSE 0 END) as delivered_count,
                SUM(CASE WHEN orders.order_status_id = 11 THEN 1 ELSE 0 END) as returned_count,
                SUM(CASE WHEN orders.order_status_id IN (1, 4, 5, 6, 8, 9) THEN 1 ELSE 0 END) as pending_count,
                COALESCE(SUM(CASE WHEN orders.order_status_id IN (7, 10) THEN COALESCE(lt.line_total, 0) ELSE 0 END), 0) as lifetime_value
            ')
            ->get()
            ->keyBy('customer_id');

        $result = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            $totalOrders = (int) ($row->total_orders ?? 0);
            $delivered = (int) ($row->delivered_count ?? 0);
            $returned = (int) ($row->returned_count ?? 0);
            $pending = (int) ($row->pending_count ?? 0);
            $ltv = round((float) ($row->lifetime_value ?? 0), 2);

            $aov = $delivered > 0 ? round($ltv / $delivered, 2) : 0.0;
            $deliveryRate = ($delivered + $returned) > 0
                ? round($delivered / ($delivered + $returned), 4)
                : null;
            $returnRate = $totalOrders > 0
                ? round(($returned / $totalOrders) * 100, 1)
                : 0.0;

            $tier = ($delivered >= 3 || $ltv >= 2500) ? 'VIP' : 'Regular';

            if (($delivered + $returned) === 0) {
                $trustLevel = 'new';
            } elseif ($deliveryRate !== null && $deliveryRate >= 0.7) {
                $trustLevel = 'trusted';
            } elseif ($deliveryRate !== null && $deliveryRate >= 0.4) {
                $trustLevel = 'medium';
            } else {
                $trustLevel = 'risky';
            }

            $result[$id] = [
                'delivered_count' => $delivered,
                'returned_count' => $returned,
                'pending_count' => $pending,
                'total_orders' => $totalOrders,
                'lifetime_value' => $ltv,
                'average_order_value' => $aov,
                'delivery_rate' => $deliveryRate,
                'return_rate' => $returnRate,
                'tier' => $tier,
                'trust_level' => $trustLevel,
            ];
        }

        return $result;
    }

    /**
     * Normalize all phones and merge duplicate customers sharing the same normalized phone number
     * within an account. Preserves all orders, addresses, phones, calls, notes, and blacklist flags.
     */
    public static function deduplicateAccount(int $accountId): array
    {
        $normalizedCount = 0;
        $mergedCustomersCount = 0;
        $groupsResolved = 0;

        // 1. Normalize only un-normalized Phone titles in chunks of 2000 (fast & low memory)
        DB::table('phones')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->whereRaw("title NOT REGEXP '^0[5-8][0-9]{8}$'")
            ->select('id', 'title')
            ->orderBy('id')
            ->chunkById(2000, function ($rows) use (&$normalizedCount) {
                foreach ($rows as $row) {
                    $norm = self::normalizePhone($row->title);
                    if ($norm !== '' && $row->title !== $norm) {
                        DB::table('phones')
                            ->where('id', $row->id)
                            ->update(['title' => $norm]);
                        $normalizedCount++;
                    }
                }
            });

        // 2. Merge duplicate Phone rows sharing the same title within this account
        $dupPhoneTitles = DB::table('phones')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->where('title', '!=', '')
            ->groupBy('title')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('title');

        foreach ($dupPhoneTitles as $title) {
            $phoneIds = DB::table('phones')
                ->where('account_id', $accountId)
                ->whereNull('deleted_at')
                ->where('title', $title)
                ->orderBy('id', 'asc')
                ->pluck('id')
                ->all();

            if (count($phoneIds) <= 1) {
                continue;
            }

            $canonicalId = $phoneIds[0];
            $dupIds = array_slice($phoneIds, 1);

            foreach ($dupIds as $dupId) {
                $pivots = DB::table('phoneables')->where('phone_id', $dupId)->get();
                foreach ($pivots as $pivot) {
                    $exists = DB::table('phoneables')
                        ->where('phone_id', $canonicalId)
                        ->where('phoneable_type', $pivot->phoneable_type)
                        ->where('phoneable_id', $pivot->phoneable_id)
                        ->exists();
                    if (!$exists) {
                        DB::table('phoneables')
                            ->where('id', $pivot->id)
                            ->update(['phone_id' => $canonicalId]);
                    } else {
                        DB::table('phoneables')->where('id', $pivot->id)->delete();
                    }
                }
                DB::table('phones')->where('id', $dupId)->update(['deleted_at' => now()]);
            }
        }

        // 3. Find phones linked to 2+ distinct active customers in SQL
        $dupCustomerPhones = DB::table('phoneables')
            ->join('phones', 'phones.id', '=', 'phoneables.phone_id')
            ->join('customers', 'customers.id', '=', 'phoneables.phoneable_id')
            ->where('phoneables.phoneable_type', Customer::class)
            ->where('customers.account_id', $accountId)
            ->whereNull('customers.deleted_at')
            ->whereNull('phones.deleted_at')
            ->whereRaw('CHAR_LENGTH(phones.title) >= 8')
            ->groupBy('phones.title')
            ->havingRaw('COUNT(DISTINCT customers.id) > 1')
            ->pluck('phones.title');

        $mergedSecondaryIds = [];

        foreach ($dupCustomerPhones as $normPhone) {
            $cIds = DB::table('phoneables')
                ->join('phones', 'phones.id', '=', 'phoneables.phone_id')
                ->join('customers', 'customers.id', '=', 'phoneables.phoneable_id')
                ->where('phoneables.phoneable_type', Customer::class)
                ->where('customers.account_id', $accountId)
                ->whereNull('customers.deleted_at')
                ->whereNull('phones.deleted_at')
                ->where('phones.title', $normPhone)
                ->pluck('customers.id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $uniqueIds = array_values(array_unique(array_diff($cIds, $mergedSecondaryIds)));
            if (count($uniqueIds) <= 1) {
                continue;
            }

            DB::transaction(function () use ($accountId, $uniqueIds, &$groupsResolved, &$mergedCustomersCount, &$mergedSecondaryIds) {
                $customers = Customer::where('account_id', $accountId)
                    ->whereIn('id', $uniqueIds)
                    ->withCount('orders')
                    ->get();

                if ($customers->count() <= 1) {
                    return;
                }

                // Sort to pick primary: non-placeholder name first, then highest orders_count, then lowest id
                $sorted = $customers->sort(function (Customer $a, Customer $b) {
                    $aReal = !self::isPlaceholderName($a->name) ? 1 : 0;
                    $bReal = !self::isPlaceholderName($b->name) ? 1 : 0;
                    if ($aReal !== $bReal) {
                        return $bReal <=> $aReal;
                    }
                    if ($a->orders_count !== $b->orders_count) {
                        return $b->orders_count <=> $a->orders_count;
                    }
                    return $a->id <=> $b->id;
                })->values();

                /** @var Customer $primary */
                $primary = $sorted->first();
                $secondaries = $sorted->slice(1);

                $groupsResolved++;

                foreach ($secondaries as $secondary) {
                    self::mergeCustomerInto($primary, $secondary);
                    $mergedSecondaryIds[] = $secondary->id;
                    $mergedCustomersCount++;
                }
            });
        }

        return [
            'normalized_phones' => $normalizedCount,
            'merged_customers' => $mergedCustomersCount,
            'groups_resolved' => $groupsResolved,
        ];
    }

    /**
     * Merge a secondary customer into a primary customer inside an active transaction.
     */
    public static function mergeCustomerInto(Customer $primary, Customer $secondary): void
    {
        if ($primary->id === $secondary->id) {
            return;
        }

        // Preserve best name
        $bestName = self::chooseBestName($primary->name, $secondary->name);
        if ($bestName !== $primary->name) {
            $primary->name = $bestName;
        }

        // Preserve blacklist status if either customer was blacklisted
        if ($secondary->is_blacklisted && !$primary->is_blacklisted) {
            $primary->is_blacklisted = true;
        }

        // Concatenate distinct notes
        $primaryNote = trim((string) $primary->note);
        $secondaryNote = trim((string) $secondary->note);
        if ($secondaryNote !== '' && $secondaryNote !== $primaryNote && !str_contains($primaryNote, $secondaryNote)) {
            $primary->note = $primaryNote !== '' ? ($primaryNote . ' | ' . $secondaryNote) : $secondaryNote;
        }

        $primary->save();

        // Reassign Orders
        Order::withTrashed()->where('customer_id', $secondary->id)->update(['customer_id' => $primary->id]);

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
        CustomerCall::where('customer_id', $secondary->id)->update(['customer_id' => $primary->id]);

        // Soft-delete secondary customer
        $secondary->delete();
    }
}
