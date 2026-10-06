<?php

namespace App\Services;

use App\Support\OverviewLabels;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Account-scoped figures for the catalog dashboard: catalog size and health,
 * stock, margin and delivery rates (per product, per size, product x size).
 */
class CatalogOverviewService
{
    /** order_statuses.id: Livrée, Payé */
    public const DELIVERED = [7, 10];
    /** Retournée */
    public const RETURNED = [11];
    /** En souffrance */
    public const TROUBLE = [9];
    /** En livraison */
    public const IN_TRANSIT = [6];

    /** Unit cost of an order line: product cost price, else the average supplier price of that variation. */
    private const LINE_COST = 'COALESCE(NULLIF(p.cost_price, 0), (SELECT AVG(sp.price) FROM supplier_pva sp WHERE sp.product_variation_attribute_id = pva.id AND sp.price > 0))';

    /** Product has a known cost: own cost price or a priced supplier variation. */
    private const PRODUCT_HAS_COST = '(p.cost_price > 0 OR EXISTS (SELECT 1 FROM supplier_pva sp JOIN product_variation_attribute v ON v.id = sp.product_variation_attribute_id WHERE v.product_id = p.id AND sp.price > 0))';

    /** type_attributes.title matching a size (TAILLE, size, Sizes, Pointure…), case-insensitive. */
    private const SIZE_TYPE_PATTERN = 'size|taille|pointure';
    private const MIN_SHIPPED_FOR_ALERT = 30;
    private const ALERT_GAP_POINTS = 5;
    private const MIN_SHIPPED_FOR_CELL = 10;
    private const MATRIX_PRODUCTS = 60;

    public function build(array $dates, int $accountId): array
    {
        $delivery = $this->deliveryOverall($dates, $accountId);

        return [
            'summary'             => $this->summary($dates, $accountId, $delivery),
            'products_by_type'    => $this->productsByType($accountId),
            'products_by_brand'   => $this->productsByBrand($dates, $accountId),
            'top_categories'      => $this->topCategories($accountId),
            'delivery'            => $delivery,
            'delivery_by_product' => $this->deliveryByProduct($dates, $accountId, $delivery['rate']),
            'delivery_by_size'    => $this->deliveryBySize($dates, $accountId, $delivery['rate']),
            'delivery_matrix'     => $this->deliveryMatrix($dates, $accountId, $delivery['rate']),
            'delivery_attempts'   => $this->deliveryAttempts($dates, $accountId),
            'quality'             => $this->quality($accountId),
        ];
    }

    // region catalog

    private function ownedProducts(int $accountId): Builder
    {
        return DB::table('products as p')
            ->whereNull('p.deleted_at')
            ->whereIn('p.id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $accountId));
    }

    private function summary(array $dates, int $accountId, array $delivery): array
    {
        $total = $this->ownedProducts($accountId)->count();
        $totalAtStart = $this->ownedProducts($accountId)->where('p.created_at', '<', $dates['current'][0])->count();
        $active = $this->ownedProducts($accountId)->where('p.statut', 1)->count();
        $brands = DB::table('brands')->where('account_id', $accountId)->whereNull('deleted_at')->count();

        $stockByProduct = $this->stockByProduct($accountId);
        $outOfStock = $stockByProduct->filter(fn ($q) => $q <= 0)->count();
        $negativeStock = $stockByProduct->filter(fn ($q) => $q < 0)->count();

        $variationStock = DB::table('product_variation_attribute as pva')
            ->leftJoin('warehouse_pva as w', 'w.product_variation_attribute_id', '=', 'pva.id')
            ->whereNull('pva.deleted_at')
            ->whereIn('pva.product_id', fn ($q) => $q->select('product_id')->from('account_product')->where('account_id', $accountId))
            ->groupBy('pva.id')
            ->selectRaw('COALESCE(SUM(w.quantity), 0) as stock')
            ->pluck('stock');

        $margin = $this->margin($dates['current'], $accountId);
        $previousMargin = $this->margin($dates['previous'], $accountId);

        return [
            'total_products'            => ['value' => $total, 'trend' => $this->trend($total, $totalAtStart)],
            'active_products'           => ['value' => $active, 'trend' => 0],
            'total_brands'              => ['value' => $brands, 'trend' => 0],
            'out_of_stock'              => ['value' => $outOfStock, 'trend' => 0],
            'out_of_stock_variations'   => ['value' => $variationStock->filter(fn ($q) => $q <= 0)->count(), 'trend' => 0],
            'negative_stock'            => ['value' => $negativeStock, 'trend' => 0],
            'profit_margin'             => ['value' => $margin, 'trend' => $margin !== null && $previousMargin !== null ? round($margin - $previousMargin, 2) : 0],
            'catalog_health'            => ['value' => $this->health($accountId, $total), 'trend' => 0],
            'delivery_rate'             => ['value' => $delivery['rate'], 'trend' => $delivery['rate'] !== null && $delivery['previous_rate'] !== null ? round($delivery['rate'] - $delivery['previous_rate'], 2) : 0],
        ];
    }

    /** @return \Illuminate\Support\Collection<int, float> product id => total stock */
    private function stockByProduct(int $accountId)
    {
        return $this->ownedProducts($accountId)
            ->leftJoin('product_variation_attribute as pva', function ($j) {
                $j->on('pva.product_id', '=', 'p.id')->whereNull('pva.deleted_at');
            })
            ->leftJoin('warehouse_pva as w', 'w.product_variation_attribute_id', '=', 'pva.id')
            ->groupBy('p.id')
            ->selectRaw('p.id, COALESCE(SUM(w.quantity), 0) as stock')
            ->pluck('stock', 'p.id');
    }

    /**
     * Revenue and cost of delivered lines whose cost is known (product cost price or supplier price).
     *
     * @return array{revenue: float, cost: float}
     */
    public function grossProfit(array $range, int $accountId): array
    {
        $row = $this->orderLines($accountId, $range)
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->whereIn('o.order_status_id', self::DELIVERED)
            ->whereRaw(self::LINE_COST . ' IS NOT NULL')
            ->selectRaw('SUM(op.price * op.quantity) as revenue, SUM(' . self::LINE_COST . ' * op.quantity) as cost')
            ->first();

        return ['revenue' => (float) ($row->revenue ?? 0), 'cost' => (float) ($row->cost ?? 0)];
    }

    /** Gross margin %, null when not computable. */
    private function margin(array $range, int $accountId): ?float
    {
        $profit = $this->grossProfit($range, $accountId);

        return $profit['revenue'] > 0
            ? round((($profit['revenue'] - $profit['cost']) / $profit['revenue']) * 100, 2)
            : null;
    }

    /** % of products having a brand, a category and a cost price. */
    private function health(int $accountId, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        $healthy = $this->ownedProducts($accountId)
            ->whereRaw(self::PRODUCT_HAS_COST)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('product_brand_source as pbs')
                ->whereColumn('pbs.product_id', 'p.id')->whereNull('pbs.deleted_at'))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('taxonomy_product as tp')
                ->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
                ->whereColumn('ap.product_id', 'p.id')->where('ap.account_id', $accountId)->whereNull('tp.deleted_at'))
            ->count();

        return round($healthy / $total * 100, 1);
    }

    private function productsByType(int $accountId)
    {
        return $this->ownedProducts($accountId)
            ->join('product_types as pt', 'pt.id', '=', 'p.product_type_id')
            ->groupBy('pt.id', 'pt.title')
            ->orderByDesc('count')
            ->selectRaw('pt.id as type_id, pt.title as type_title, COUNT(p.id) as count')
            ->get()
            ->map(fn ($row) => (object) [
                'type_name' => OverviewLabels::productType($row->type_id, $row->type_title),
                'count'     => $row->count,
            ]);
    }

    private function productsByBrand(array $dates, int $accountId)
    {
        $counts = DB::table('brands as b')
            ->join('brand_source as bs', 'bs.brand_id', '=', 'b.id')
            ->leftJoin('product_brand_source as pbs', function ($j) {
                $j->on('pbs.brand_source_id', '=', 'bs.id')->whereNull('pbs.deleted_at');
            })
            ->where('bs.account_id', $accountId)->whereNull('b.deleted_at')
            ->groupBy('b.id', 'b.title')
            ->selectRaw('b.title as brand_name, COUNT(DISTINCT pbs.product_id) as count')
            ->pluck('count', 'brand_name');

        $revenues = $this->orderLines($accountId, $dates['current'])
            ->join('product_brand_source as pbs', 'pbs.product_id', '=', 'pva.product_id')
            ->join('brand_source as bs', 'bs.id', '=', 'pbs.brand_source_id')
            ->join('brands as b', 'b.id', '=', 'bs.brand_id')
            ->whereIn('o.order_status_id', self::DELIVERED)
            ->whereNull('pbs.deleted_at')
            ->groupBy('b.id', 'b.title')
            ->selectRaw('b.title as brand_name, SUM(op.price * op.quantity) as revenue')
            ->pluck('revenue', 'brand_name');

        return collect($counts)->keys()->merge(collect($revenues)->keys())->unique()
            ->map(fn ($name) => [
                'brand_name' => $name,
                'count'      => (int) ($counts[$name] ?? 0),
                'revenue'    => round((float) ($revenues[$name] ?? 0), 2),
            ])
            ->filter(fn ($r) => $r['count'] > 0 || $r['revenue'] > 0)
            ->sortByDesc(fn ($r) => [$r['revenue'], $r['count']])
            ->take(12)->values();
    }

    private function topCategories(int $accountId)
    {
        return DB::table('taxonomies as t')
            ->join('taxonomy_product as tp', 'tp.taxonomy_id', '=', 't.id')
            ->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
            ->where('ap.account_id', $accountId)->whereNull('tp.deleted_at')->whereNull('t.deleted_at')
            ->groupBy('t.id', 't.title')
            ->orderByDesc('products_count')->limit(8)
            ->selectRaw('t.title as taxonomy_name, COUNT(DISTINCT ap.product_id) as products_count')
            ->get();
    }

    /** Data issues to fix, with counts. */
    private function quality(int $accountId): array
    {
        $withoutBrand = $this->ownedProducts($accountId)->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('product_brand_source as pbs')->whereColumn('pbs.product_id', 'p.id')->whereNull('pbs.deleted_at'))->count();

        $withoutCategory = $this->ownedProducts($accountId)->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('taxonomy_product as tp')->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
            ->whereColumn('ap.product_id', 'p.id')->where('ap.account_id', $accountId)->whereNull('tp.deleted_at'))->count();

        $withoutCost = $this->ownedProducts($accountId)->whereRaw('NOT ' . self::PRODUCT_HAS_COST)->count();

        $duplicateCategories = DB::table('taxonomies as t')
            ->join('taxonomy_product as tp', 'tp.taxonomy_id', '=', 't.id')
            ->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
            ->where('ap.account_id', $accountId)->whereNull('t.deleted_at')
            ->selectRaw('LOWER(TRIM(t.title)) as k, COUNT(DISTINCT t.id) as c')
            ->groupBy('k')->havingRaw('COUNT(DISTINCT t.id) > 1')->count();

        return [
            'without_brand'        => $withoutBrand,
            'without_category'     => $withoutCategory,
            'without_cost_price'   => $withoutCost,
            'duplicate_categories' => $duplicateCategories,
        ];
    }

    /**
     * The products and categories behind each quality counter, so they can be fixed one by one.
     */
    public function qualityIssues(int $accountId): array
    {
        $titles = fn (Builder $q) => $q->orderBy('p.title')->get(['p.id', 'p.title'])->all();

        $withoutBrand = $titles($this->ownedProducts($accountId)->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('product_brand_source as pbs')->whereColumn('pbs.product_id', 'p.id')->whereNull('pbs.deleted_at')));

        $withoutCategory = $titles($this->ownedProducts($accountId)->whereNotExists(fn ($q) => $q->select(DB::raw(1))
            ->from('taxonomy_product as tp')->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
            ->whereColumn('ap.product_id', 'p.id')->where('ap.account_id', $accountId)->whereNull('tp.deleted_at')));

        $withoutCost = $titles($this->ownedProducts($accountId)->whereRaw('NOT ' . self::PRODUCT_HAS_COST));

        $stock = $this->stockByProduct($accountId);
        $negative = $this->ownedProducts($accountId)->whereIn('p.id', $stock->filter(fn ($q) => $q < 0)->keys())
            ->orderBy('p.title')->get(['p.id', 'p.title'])
            ->map(fn ($p) => ['id' => $p->id, 'title' => $p->title, 'stock' => (float) $stock[$p->id]])->all();

        $categories = DB::table('taxonomies as t')
            ->join('taxonomy_product as tp', 'tp.taxonomy_id', '=', 't.id')
            ->join('account_product as ap', 'ap.id', '=', 'tp.account_product_id')
            ->where('ap.account_id', $accountId)->whereNull('t.deleted_at')->whereNull('tp.deleted_at')
            ->groupBy('t.id', 't.title')
            ->selectRaw('t.id, t.title, COUNT(DISTINCT ap.product_id) as products')
            ->get();

        $duplicates = $categories
            ->groupBy(fn ($c) => mb_strtolower(trim($c->title)))
            ->filter(fn ($group) => $group->count() > 1)
            ->map(fn ($group) => $group->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'products' => (int) $c->products])->values())
            ->values();

        return [
            'without_brand'        => $withoutBrand,
            'without_category'     => $withoutCategory,
            'without_cost_price'   => $withoutCost,
            'negative_stock'       => $negative,
            'duplicate_categories' => $duplicates,
        ];
    }

    // endregion

    // region delivery

    /** order lines (order_pva) of the account's orders created in the range. */
    private function orderLines(int $accountId, array $range): Builder
    {
        return DB::table('order_pva as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'op.product_variation_attribute_id')
            ->where('o.account_id', $accountId)
            ->whereNull('o.deleted_at')->whereNull('op.deleted_at')
            ->whereBetween('o.created_at', $range);
    }

    /**
     * order lines of the account's orders put in a pickup (bon de ramassage) created in the range:
     * the period is the pickup date, so recent deliveries of older orders are not lost.
     */
    private function shippedLines(int $accountId, array $range): Builder
    {
        return DB::table('order_pva as op')
            ->join('orders as o', 'o.id', '=', 'op.order_id')
            ->join('pickups as pk', 'pk.id', '=', 'o.pickup_id')
            ->join('product_variation_attribute as pva', 'pva.id', '=', 'op.product_variation_attribute_id')
            ->where('o.account_id', $accountId)
            ->whereNull('o.deleted_at')->whereNull('op.deleted_at')->whereNull('pk.deleted_at')
            ->whereBetween('pk.created_at', $range);
    }

    private function deliverySelect(): string
    {
        $in = fn (array $ids) => implode(',', array_map('intval', $ids));

        return 'COUNT(DISTINCT o.id) as orders,'
            . ' COALESCE(SUM(op.quantity), 0) as quantity,'
            . ' COALESCE(SUM(CASE WHEN o.order_status_id IN (' . $in(self::DELIVERED) . ') THEN op.quantity ELSE 0 END), 0) as delivered,'
            . ' COALESCE(SUM(CASE WHEN o.order_status_id IN (' . $in(self::RETURNED) . ') THEN op.quantity ELSE 0 END), 0) as returned,'
            . ' COALESCE(SUM(CASE WHEN o.order_status_id IN (' . $in(self::TROUBLE) . ') THEN op.quantity ELSE 0 END), 0) as trouble,'
            . ' COALESCE(SUM(CASE WHEN o.order_status_id IN (' . $in(self::IN_TRANSIT) . ') THEN op.quantity ELSE 0 END), 0) as in_transit';
    }

    private function deliveryStats(object $row, ?float $overallRate = null): array
    {
        $delivered = (int) $row->delivered;
        $returned = (int) $row->returned;
        $trouble = (int) $row->trouble;
        $shipped = $delivered + $returned + $trouble;
        $rate = $shipped > 0 ? round($delivered / $shipped * 100, 1) : null;

        return [
            'quantity'   => (int) $row->quantity,
            'delivered'  => $delivered,
            'returned'   => $returned,
            'trouble'    => $trouble,
            'in_transit' => (int) $row->in_transit,
            'shipped'    => $shipped,
            'rate'       => $rate,
            'return_rate' => $shipped > 0 ? round($returned / $shipped * 100, 1) : null,
            'is_problem' => $overallRate !== null && $rate !== null
                && $shipped >= self::MIN_SHIPPED_FOR_ALERT
                && $rate < $overallRate - self::ALERT_GAP_POINTS,
        ];
    }

    private function deliveryOverall(array $dates, int $accountId): array
    {
        $current = $this->orderLines($accountId, $dates['current'])->selectRaw($this->deliverySelect())->first();
        $previous = $this->orderLines($accountId, $dates['previous'])->selectRaw($this->deliverySelect())->first();

        $stats = $this->deliveryStats($current);
        $previousStats = $this->deliveryStats($previous);

        return $stats + [
            'previous_rate' => $previousStats['rate'],
            'orders'        => (int) $current->orders,
        ];
    }

    private function deliveryByProduct(array $dates, int $accountId, ?float $overallRate, int $limit = 15)
    {
        return $this->orderLines($accountId, $dates['current'])
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->groupBy('p.id', 'p.title')
            ->selectRaw('p.id, p.title, ' . $this->deliverySelect())
            ->get()
            ->map(fn ($r) => ['id' => $r->id, 'title' => $r->title] + $this->deliveryStats($r, $overallRate))
            ->filter(fn ($r) => $r['shipped'] > 0)
            ->sortByDesc('shipped')->take($limit)->values();
    }

    private function sizeQuery(int $accountId, array $range, bool $byPickup = false): Builder
    {
        return ($byPickup ? $this->shippedLines($accountId, $range) : $this->orderLines($accountId, $range))
            ->join('variation_attributes as va', 'va.variation_attribute_id', '=', 'pva.variation_attribute_id')
            ->join('attributes as a', 'a.id', '=', 'va.attribute_id')
            ->join('type_attributes as ta', 'ta.id', '=', 'a.types_attribute_id')
            ->whereRaw('LOWER(ta.title) REGEXP ?', [self::SIZE_TYPE_PATTERN]);
    }

    private function sortSizes($rows, string $key = 'size')
    {
        return $rows->sort(function ($a, $b) use ($key) {
            return is_numeric($a[$key]) && is_numeric($b[$key])
                ? (float) $a[$key] <=> (float) $b[$key]
                : strcmp((string) $a[$key], (string) $b[$key]);
        })->values();
    }

    private function deliveryBySize(array $dates, int $accountId, ?float $overallRate)
    {
        $rows = $this->sizeQuery($accountId, $dates['current'])
            ->groupBy('a.title')
            ->selectRaw('a.title as size, ' . $this->deliverySelect())
            ->get()
            ->map(fn ($r) => ['size' => $r->size] + $this->deliveryStats($r, $overallRate));

        return $this->sortSizes($rows);
    }

    /** Product x size delivery figures for the busiest products (heatmap), with a total per product and its worst size. */
    private function deliveryMatrix(array $dates, int $accountId, ?float $overallRate): array
    {
        $topProducts = $this->deliveryByProduct($dates, $accountId, $overallRate, self::MATRIX_PRODUCTS);
        if ($topProducts->isEmpty()) {
            return ['sizes' => [], 'rows' => []];
        }

        $cells = $this->sizeQuery($accountId, $dates['current'])
            ->whereIn('pva.product_id', $topProducts->pluck('id'))
            ->groupBy('pva.product_id', 'a.title')
            ->selectRaw('pva.product_id as product_id, a.title as size, ' . $this->deliverySelect())
            ->get()
            ->groupBy('product_id');

        $sizes = $this->sortSizes($cells->flatten(1)->pluck('size')->unique()->map(fn ($s) => ['size' => $s]))->pluck('size');

        $rows = $topProducts->map(function ($product) use ($cells, $overallRate) {
            $bySize = collect($cells[$product['id']] ?? [])->keyBy('size');
            $stats = $bySize->map(fn ($r) => $this->deliveryStats($r, $overallRate));

            // worst size = highest return rate among sizes with enough volume to mean something
            $worst = $stats->filter(fn ($c) => $c['shipped'] >= self::MIN_SHIPPED_FOR_CELL && $c['return_rate'] !== null)
                ->sortByDesc('return_rate')->keys()->first();

            return [
                'id'         => $product['id'],
                'title'      => $product['title'],
                'total'      => Arr::except($product, ['id', 'title']),
                'worst_size' => $worst,
                'cells'      => $stats->all(),
            ];
        })->values();

        return ['sizes' => $sizes, 'rows' => $rows];
    }

    // endregion

    // region delivery attempts

    /**
     * Attempts needed to deliver one pair = pairs shipped / pairs delivered.
     * "Shipped" = sent out in a pickup and with a known outcome (delivered, returned or in trouble);
     * parcels still in transit are shown apart so they do not inflate the ratio.
     * E.g. 10 pairs shipped, 2 delivered: 5 shipments for each delivered pair.
     */
    public function deliveryAttempts(array $dates, int $accountId): array
    {
        $label = "(SELECT GROUP_CONCAT(at.title ORDER BY ty.title SEPARATOR ' · ')
            FROM variation_attributes va JOIN attributes at ON at.id = va.attribute_id
            JOIN type_attributes ty ON ty.id = at.types_attribute_id
            WHERE va.variation_attribute_id = pva.variation_attribute_id)";

        $overall = $this->shippedLines($accountId, $dates['current'])->selectRaw($this->deliverySelect())->first();

        $byVariation = $this->shippedLines($accountId, $dates['current'])
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->groupBy('pva.id', 'p.id', 'p.title', 'pva.variation_attribute_id')
            ->selectRaw("p.id as product_id, p.title as product_title, pva.id as pva_id, $label as variation, " . $this->deliverySelect())
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_title' => $r->product_title, 'pva_id' => $r->pva_id, 'variation' => $r->variation] + $this->attemptStats($r))
            ->filter(fn ($r) => $r['shipped'] > 0)
            ->sortByDesc('shipped')->values();

        $byProduct = $this->shippedLines($accountId, $dates['current'])
            ->join('products as p', 'p.id', '=', 'pva.product_id')
            ->groupBy('p.id', 'p.title')
            ->selectRaw('p.id as product_id, p.title as product_title, ' . $this->deliverySelect())
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'product_title' => $r->product_title] + $this->attemptStats($r))
            ->filter(fn ($r) => $r['shipped'] > 0)
            ->sortByDesc('shipped')->values();

        $bySize = $this->sortSizes(
            $this->sizeQuery($accountId, $dates['current'], true)
                ->groupBy('a.title')
                ->selectRaw('a.title as size, ' . $this->deliverySelect())
                ->get()
                ->map(fn ($r) => ['size' => $r->size] + $this->attemptStats($r))
                ->filter(fn ($r) => $r['shipped'] > 0)
        );

        return [
            'overall'      => $this->attemptStats($overall),
            'by_product'   => $byProduct,
            'by_size'      => $bySize,
            'by_variation' => $byVariation,
        ];
    }

    private function attemptStats(object $row): array
    {
        $stats = $this->deliveryStats($row);
        $delivered = $stats['delivered'];

        return [
            'shipped'      => $stats['shipped'],
            'delivered'    => $delivered,
            'returned'     => $stats['returned'],
            'trouble'      => $stats['trouble'],
            'in_transit'   => $stats['in_transit'],
            'delivery_rate' => $stats['rate'],
            // shipments needed per delivered pair; null while nothing has been delivered
            'attempts'     => $delivered > 0 ? round($stats['shipped'] / $delivered, 2) : null,
        ];
    }

    // endregion

    private function trend(float $current, float $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / $previous) * 100, 2);
    }
}
