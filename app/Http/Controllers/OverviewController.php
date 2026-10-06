<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\Order;
use App\Services\CatalogOverviewService;
use App\Services\OperationsOverviewService;
use App\Support\OverviewLabels;

class OverviewController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(private readonly OperationsOverviewService $operations)
    {
    }

    //endregion

    //region API Methods
    public function sales(Request $request)
    {
        return $this->buildResponse($request, 'sales', function ($dates, $accountId) {
            return [
                'summary'              => $this->getSalesSummary($dates, $accountId),
                'orders_by_status'     => $this->getOrdersByStatus($dates, $accountId),
                'revenue_by_day'       => $this->getRevenueByDay($dates, $accountId),
                'top_selling_products' => $this->getTopSellingProducts($dates, $accountId),
                'recent_orders'        => $this->getRecentOrders($dates, $accountId),
                'orders_by_brand'      => $this->getOrdersByBrand($dates, $accountId),
                'orders_by_source'     => $this->getOrdersBySource($dates, $accountId),
            ];
        });
    }

    public function logistics(Request $request)
    {
        $filters = OperationsOverviewService::logisticsFilters($request->query());

        return $this->buildResponse($request, 'logistics', fn ($dates, $accountId) => $this->operations->logistics($dates, (int) $accountId, $filters), $filters);
    }

    public function logisticsExceptions(Request $request)
    {
        $accountId = (int) getAccountUser()->account_id;
        $filters = OperationsOverviewService::logisticsFilters($request->query());
        $stuckDays = (int) $request->query('stuck_days', OperationsOverviewService::DEFAULT_STUCK_DAYS);

        return response()->json([
            'status' => 'success',
            'data'   => $this->operations->logisticsExceptions($accountId, $filters, $stuckDays),
        ]);
    }

    public function logisticsCities(Request $request)
    {
        $filters = OperationsOverviewService::logisticsFilters($request->query());

        return $this->buildResponse($request, 'logistics_cities', fn ($dates, $accountId) => $this->operations->logisticsCities($dates['current'], (int) $accountId, $filters), $filters);
    }

    public function logisticsBilling(Request $request)
    {
        $filters = OperationsOverviewService::logisticsFilters($request->query());

        return $this->buildResponse($request, 'logistics_billing', fn ($dates, $accountId) => $this->operations->logisticsBilling($dates['current'], (int) $accountId, $filters), $filters);
    }

    public function procurement(Request $request)
    {
        return $this->buildResponse($request, 'procurement', fn ($dates, $accountId) => $this->operations->procurement($dates, (int) $accountId));
    }

    public function catalog(Request $request)
    {
        return $this->buildResponse($request, 'catalog', function ($dates, $accountId) {
            return $this->getCatalogData($dates, $accountId);
        });
    }

    public function catalogQuality(Request $request)
    {
        $accountId = (int) getAccountUser()->account_id;

        return response()->json([
            'status' => 'success',
            'data'   => app(CatalogOverviewService::class)->qualityIssues($accountId),
        ]);
    }

    public function finance(Request $request)
    {
        return $this->buildResponse($request, 'finance', fn ($dates, $accountId) => $this->operations->finance($dates, (int) $accountId));
    }

    public function administration(Request $request)
    {
        return $this->buildResponse($request, 'administration', fn ($dates, $accountId) => $this->operations->administration($dates, (int) $accountId));
    }

    //endregion

    //region Data Fetching (Sales)
    private function getSalesSummary($dates, $accountId)
    {
        $currentRevenue = $this->getRevenueQuery($dates['current'], $accountId)->sum(DB::raw('order_pva.price * order_pva.quantity'));
        $prevRevenue = $this->getRevenueQuery($dates['previous'], $accountId)->sum(DB::raw('order_pva.price * order_pva.quantity'));

        $currentOrders = Order::where('account_id', $accountId)->whereBetween('created_at', $dates['current'])->count();
        $prevOrders = Order::where('account_id', $accountId)->whereBetween('created_at', $dates['previous'])->count();
        
        $currentAvgOrderValue = $currentOrders > 0 ? $currentRevenue / $currentOrders : 0;
        $prevAvgOrderValue = $prevOrders > 0 ? $prevRevenue / $prevOrders : 0;
        
        $currentOffers = DB::table('offers')->where('account_id', $accountId)->whereBetween('created_at', $dates['current'])->whereNull('deleted_at')->count();
        $prevOffers = DB::table('offers')->where('account_id', $accountId)->whereBetween('created_at', $dates['previous'])->whereNull('deleted_at')->count();

        return [
            'total_revenue'         => $this->formatMetric($currentRevenue, $prevRevenue, true),
            'total_orders'          => $this->formatMetric($currentOrders, $prevOrders),
            'avg_order_value'       => $this->formatMetric($currentAvgOrderValue, $prevAvgOrderValue, true),
            'total_offers'          => $this->formatMetric($currentOffers, $prevOffers),
            'customer_satisfaction'  => $this->getCustomerSatisfaction($dates, $accountId),
            'reviewed_shipped_ratio' => $this->getReviewedShippedRatio($dates, $accountId),
        ];
    }

    private function getReviewedShippedRatio($dates, $accountId)
    {
        $shippedStatuses = [6, 7, 9, 10, 11];

        $currentShippedCount = Order::where('account_id', $accountId)
            ->whereIn('order_status_id', $shippedStatuses)
            ->whereBetween('created_at', $dates['current'])
            ->count();

        $currentReviewedCount = Order::where('account_id', $accountId)
            ->whereIn('order_status_id', $shippedStatuses)
            ->whereBetween('created_at', $dates['current'])
            ->whereHas('review')
            ->count();

        $currentRatio = $currentShippedCount > 0 ? ($currentReviewedCount / $currentShippedCount) * 100 : 0;

        $prevShippedCount = Order::where('account_id', $accountId)
            ->whereIn('order_status_id', $shippedStatuses)
            ->whereBetween('created_at', $dates['previous'])
            ->count();

        $prevReviewedCount = Order::where('account_id', $accountId)
            ->whereIn('order_status_id', $shippedStatuses)
            ->whereBetween('created_at', $dates['previous'])
            ->whereHas('review')
            ->count();

        $prevRatio = $prevShippedCount > 0 ? ($prevReviewedCount / $prevShippedCount) * 100 : 0;

        return [
            'value' => round($currentRatio, 2),
            'trend' => $this->calculateTrend($currentRatio, $prevRatio),
        ];
    }
    
    private function getRevenueQuery($dates, $accountId)
    {
        return DB::table('order_pva')
            ->join('orders', 'order_pva.order_id', '=', 'orders.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates)
            ->whereNull('orders.deleted_at')
            ->whereNotIn('order_pva.order_status_id', [2, 3])
            // abandoned, out of stock, cancelled and returned orders are not revenue
            ->whereNotIn('orders.order_status_id', [2, 3, 8, 11]);
    }
    
    private function getOrdersByStatus($dates, $accountId)
    {
        return DB::table('orders')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->whereNull('orders.deleted_at')
            ->select('order_statuses.id', 'order_statuses.title', DB::raw('count(*) as count'))
            ->groupBy('order_statuses.id', 'order_statuses.title')
            ->get()
            ->map(fn ($row) => (object) [
                'status' => OverviewLabels::orderStatus($row->id, $row->title),
                'count'  => $row->count,
            ]);
    }
    
    private function getRevenueByDay($dates, $accountId)
    {
        return $this->getRevenueQuery($dates['current'], $accountId)
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('SUM(order_pva.price * order_pva.quantity) as revenue'),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }
    
    private function getTopSellingProducts($dates, $accountId)
    {
        return $this->getRevenueQuery($dates['current'], $accountId)
            ->join('product_variation_attribute', 'order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('products', 'product_variation_attribute.product_id', '=', 'products.id')
            ->select('products.id', 'products.title as name', DB::raw('SUM(order_pva.quantity) as quantity_sold'))
            ->groupBy('products.id', 'products.title')
            ->orderByDesc('quantity_sold')
            ->limit(5)
            ->get();
    }
    
    private function getRecentOrders($dates, $accountId)
    {
        return Order::with(['customer', 'orderStatus'])
            ->where('account_id', $accountId)
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($order) => [
                'id'         => $order->id,
                'code'       => $order->code,
                'customer'   => $order->customer?->name ?? 'N/A',
                'amount'     => $order->calculateActivePvasTotalValue(),
                'status'     => OverviewLabels::orderStatus($order->order_status_id, $order->orderStatus?->title) ?? 'N/A',
                'created_at' => $order->created_at->toDateString(),
            ]);
    }

    private function getOrdersByBrand($dates, $accountId)
    {
        return DB::table('orders')
            ->join('brand_source', 'orders.brand_source_id', '=', 'brand_source.id')
            ->join('brands', 'brand_source.brand_id', '=', 'brands.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->whereNull('orders.deleted_at')
            ->select('brands.title as brand', DB::raw('count(*) as count'))
            ->groupBy('brands.id', 'brands.title')
            ->get();
    }

    private function getOrdersBySource($dates, $accountId)
    {
        return DB::table('orders')
            ->join('brand_source', 'orders.brand_source_id', '=', 'brand_source.id')
            ->join('sources', 'brand_source.source_id', '=', 'sources.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->whereNull('orders.deleted_at')
            ->select('sources.title as source', DB::raw('count(*) as count'))
            ->groupBy('sources.id', 'sources.title')
            ->get();
    }

    //endregion

    //region Data Fetching (Catalog)
    private function getCatalogData($dates, $accountId): array
    {
        return app(CatalogOverviewService::class)->build($dates, (int) $accountId);
    }

    //endregion

    //region Data Fetching (Reviews)
    private function getCustomerSatisfaction($dates, $accountId)
    {
        $currentAvg = DB::table('review_answers')
            ->join('reviews', 'review_answers.review_id', '=', 'reviews.id')
            ->join('orders', 'reviews.order_id', '=', 'orders.id')
            ->join('review_questions', 'review_answers.review_question_id', '=', 'review_questions.id')
            ->where('orders.account_id', $accountId)
            ->where('review_questions.type', 'stars')
            ->whereBetween('orders.created_at', $dates['current'])
            ->avg('review_answers.answer_value');

        $previousAvg = DB::table('review_answers')
            ->join('reviews', 'review_answers.review_id', '=', 'reviews.id')
            ->join('orders', 'reviews.order_id', '=', 'orders.id')
            ->join('review_questions', 'review_answers.review_question_id', '=', 'review_questions.id')
            ->where('orders.account_id', $accountId)
            ->where('review_questions.type', 'stars')
            ->whereBetween('orders.created_at', $dates['previous'])
            ->avg('review_answers.answer_value');

        return $this->formatMetric($currentAvg, $previousAvg);
    }

    //endregion

    //region Helpers
    private function buildResponse(Request $request, $cacheKey, callable $dataCallback, array $filters = [])
    {
        $dates = $this->getDateRanges($request);
        $accountId = getAccountUser()->account_id;
        $filterKey = $filters ? '_' . md5(json_encode($filters)) : '';

        $fullCacheKey = "overview_v4_{$cacheKey}_{$accountId}_{$dates['current'][0]->toDateString()}_{$dates['current'][1]->toDateString()}{$filterKey}";

        $data = Cache::remember($fullCacheKey, self::CACHE_TTL, fn() => $dataCallback($dates, $accountId));

        return response()->json([
            'status' => 'success',
            'data'   => $data,
        ]);
    }
    
    private function getDateRanges(Request $request): array
    {
        $startDate = Carbon::parse($request->query('start_date', now()->subDays(29)))->startOfDay();
        $endDate = Carbon::parse($request->query('end_date', now()))->endOfDay();
        
        $diffInDays = $startDate->diffInDays($endDate);
        
        $prevStartDate = $startDate->copy()->subDays($diffInDays + 1);
        $prevEndDate = $startDate->copy()->subSecond();

        return [
            'current'  => [$startDate, $endDate],
            'previous' => [$prevStartDate, $prevEndDate],
        ];
    }

    private function calculateTrend($current, $previous): float
    {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : 0.0;
        }
        return round((($current - $previous) / $previous) * 100, 2);
    }
    
    private function formatMetric($current, $previous, $isCurrency = false): array
    {
        return [
            'value' => $isCurrency ? round($current, 2) : $current,
            'trend' => $this->calculateTrend($current, $previous),
        ];
    }
    //endregion
}
