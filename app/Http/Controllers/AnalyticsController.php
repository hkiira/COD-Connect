<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Order; // Assuming you have an Order model
use App\Models\City; // Assuming you have a City model
use App\Models\Product; // Assuming you have a Product model

class AnalyticsController extends Controller
{
    public function kpi(Request $request)
    {
        $filters = $this->getDateFilters($request);

        // Current period data
        $currentData = $this->getKpiData($filters['current_start'], $filters['current_end'], $request->city_id);

        // Previous period data
        $previousData = $this->getKpiData($filters['previous_start'], $filters['previous_end'], $request->city_id);

        // Calculate trends
        $trend = function ($current, $previous) {
            if ($previous == 0) {
                return $current > 0 ? 100 : 0;
            }
            return (($current - $previous) / $previous) * 100;
        };

        return response()->json([
            'total_sales' => [
                'value' => $currentData['total_sales'],
                'trend' => $trend($currentData['total_sales'], $previousData['total_sales']),
            ],
            'net_profit' => [
                'value' => $currentData['net_profit'],
                'trend' => $trend($currentData['net_profit'], $previousData['net_profit']),
            ],
            'delivery_success_rate' => [
                'value' => $currentData['delivery_success_rate'],
                'trend' => $currentData['delivery_success_rate'] - $previousData['delivery_success_rate'],
            ],
            'total_refused_value' => [
                'value' => $currentData['total_refused_value'],
                'trend' => $trend($currentData['total_refused_value'], $previousData['total_refused_value']),
            ],
        ]);
    }

    private function getDateFilters(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $start = new \DateTime($startDate);
        $end = new \DateTime($endDate);
        $diff = $start->diff($end);

        $previousEnd = (clone $start)->modify('-1 day');
        $previousStart = (clone $previousEnd)->modify('-' . $diff->days . ' days');

        return [
            'current_start' => $start->format('Y-m-d'),
            'current_end' => $end->format('Y-m-d'),
            'previous_start' => $previousStart->format('Y-m-d'),
            'previous_end' => $previousEnd->format('Y-m-d'),
        ];
    }

    private function getKpiData($startDate, $endDate, $cityId)
    {
        $query = Order::whereBetween('orders.created_at', [$startDate, $endDate])
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $orders = $query->select('orders.*', 'order_statuses.title as status_title')->get();

        $deliveredOrders = $orders->where('status_title', 'delivered');
        $refusedOrders = $orders->where('status_title', 'refused');

        $totalSales = $deliveredOrders->sum('total');

        // Calculate Net Profit
        $totalCost = 0;
        $deliveredOrderIds = $deliveredOrders->pluck('id');
        
        $orderProducts = DB::table('order_pva')
            ->join('product_variation_attribute', 'order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('products', 'product_variation_attribute.product_id', '=', 'products.id')
            ->whereIn('order_pva.order_id', $deliveredOrderIds)
            ->select('products.cost_price', 'order_pva.quantity')
            ->get();

        foreach ($orderProducts as $item) {
            $totalCost += $item->cost_price * $item->quantity;
        }

        $netProfit = $totalSales - $totalCost;

        $totalOrdersCount = $orders->count();
        $successfulDeliveries = $deliveredOrders->count();
        $deliverySuccessRate = $totalOrdersCount > 0 ? ($successfulDeliveries / $totalOrdersCount) * 100 : 0;

        $totalRefusedValue = $refusedOrders->sum('total');

        return [
            'total_sales' => $totalSales,
            'net_profit' => $netProfit,
            'delivery_success_rate' => $deliverySuccessRate,
            'total_refused_value' => $totalRefusedValue,
        ];
    }

    public function salesVsRefusals(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $cityId = $request->input('city_id');

        $query = Order::join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->select(
                DB::raw('DATE(orders.created_at) as date'),
                DB::raw('SUM(CASE WHEN order_statuses.title = "delivered" THEN 1 ELSE 0 END) as successful'),
                DB::raw('SUM(CASE WHEN order_statuses.title = "refused" THEN 1 ELSE 0 END) as refused')
            )
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->groupBy('date')
            ->orderBy('date', 'asc');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $data = $query->get();

        return response()->json($data);
    }

    public function sizeMatrix(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $cityId = $request->input('city_id');

        $query = DB::table('order_pva')
            ->join('orders', 'order_pva.order_id', '=', 'orders.id')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->join('product_variation_attribute', 'order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('variation_attributes', 'product_variation_attribute.variation_attribute_id', '=', 'variation_attributes.id')
            ->join('attributes', 'variation_attributes.attribute_id', '=', 'attributes.id')
            ->join('types_attributes', 'attributes.types_attribute_id', '=', 'types_attributes.id')
            ->where('types_attributes.title', 'size') // Filter for the 'size' attribute type
            ->select(
                'attributes.title as size',
                DB::raw('SUM(CASE WHEN order_statuses.title = "delivered" THEN 1 ELSE 0 END) as sales'),
                DB::raw('SUM(CASE WHEN order_statuses.title = "refused" THEN 1 ELSE 0 END) as refusals')
            )
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->groupBy('size')
            ->orderBy('size');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $data = $query->get();

        return response()->json($data);
    }

    public function geographic(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $query = City::select('cities.title as city')
            ->addSelect(DB::raw('COUNT(orders.id) as total_orders'))
            ->addSelect(DB::raw('SUM(CASE WHEN order_statuses.title = "delivered" THEN 1 ELSE 0 END) * 100.0 / COUNT(orders.id) as success_rate'))
            ->join('orders', 'cities.id', '=', 'orders.city_id')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->groupBy('cities.id', 'cities.title')
            ->orderByDesc('success_rate');

        $data = $query->get();

        return response()->json($data);
    }

    public function refusalReasons(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $cityId = $request->input('city_id');

        $query = DB::table('comments')
            ->join('order_comment', 'comments.id', '=', 'order_comment.comment_id')
            ->join('orders', 'order_comment.order_id', '=', 'orders.id')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->where('order_statuses.title', 'refused')
            ->where('comments.type', 'refused') // Assuming 'refused' type comments are refusal reasons
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->select('comments.comment as reason', DB::raw('count(*) as count'))
            ->groupBy('reason');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $reasons = $query->get();
        $totalRefusals = $reasons->sum('count');

        $data = $reasons->map(function ($reason) use ($totalRefusals) {
            return [
                'reason' => $reason->reason,
                'count' => $reason->count,
                'percentage' => $totalRefusals > 0 ? ($reason->count / $totalRefusals) * 100 : 0,
            ];
        });

        return response()->json($data);
    }

    public function acquisitionSources(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $cityId = $request->input('city_id');

        $query = Order::join('brand_source', 'orders.brand_source_id', '=', 'brand_source.id')
            ->join('sources', 'brand_source.source_id', '=', 'sources.id')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->select(
                'sources.title as source',
                'brand_source.id as brand_source_id', // To identify the group for cost calculation
                DB::raw('COUNT(orders.id) as orders'),
                DB::raw('SUM(orders.total) as total_revenue'),
                DB::raw('SUM(CASE WHEN order_statuses.title = "refused" THEN 1 ELSE 0 END) * 100.0 / COUNT(orders.id) as refusal_rate')
            )
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->groupBy('source', 'brand_source_id');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $data = $query->get()->map(function($item) {
            // Simplified ROI, as cost calculation per source is complex
            // A more accurate ROI would require tracking ad spend per source.
            $orderIds = Order::where('brand_source_id', $item->brand_source_id)->pluck('id');
            $totalCost = $this->calculateCostForOrders($orderIds);
            $roi = $totalCost > 0 ? ($item->total_revenue - $totalCost) / $totalCost : 0;

            return [
                'source' => $item->source,
                'roi' => $roi,
                'refusal_rate' => $item->refusal_rate,
                'orders' => $item->orders,
            ];
        });


        return response()->json($data);
    }
    
    private function calculateCostForOrders($orderIds)
    {
        $orderProducts = DB::table('order_pva')
            ->join('product_variation_attribute', 'order_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('products', 'product_variation_attribute.product_id', '=', 'products.id')
            ->whereIn('order_pva.order_id', $orderIds)
            ->select('products.cost_price', 'order_pva.quantity')
            ->get();

        $totalCost = 0;
        foreach ($orderProducts as $item) {
            $totalCost += $item->cost_price * $item->quantity;
        }
        return $totalCost;
    }

    public function productPerformance(Request $request)
    {
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());
        $cityId = $request->input('city_id');
        $limit = $request->input('limit', 10);

        $query = Product::select(
                'products.title as product_name',
                'products.reference as sku',
                DB::raw('SUM(CASE WHEN order_statuses.title = "delivered" THEN order_pva.quantity ELSE 0 END) as total_sold'),
                DB::raw('SUM(CASE WHEN order_statuses.title = "refused" THEN order_pva.quantity ELSE 0 END) as total_refused'),
                DB::raw('AVG(order_pva.price - products.cost_price) as net_profit_per_item')
            )
            ->join('product_variation_attribute', 'products.id', '=', 'product_variation_attribute.product_id')
            ->join('order_pva', 'product_variation_attribute.id', '=', 'order_pva.product_variation_attribute_id')
            ->join('orders', 'order_pva.order_id', '=', 'orders.id')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->whereBetween('orders.created_at', [$startDate, $endDate])
            ->groupBy('products.id', 'products.title', 'products.reference');

        if ($cityId) {
            $query->where('orders.city_id', $cityId);
        }

        $data = $query->paginate($limit);
        
        // Manually add stock_velocity
        $data->getCollection()->transform(function ($item) {
            if ($item->total_sold > 100) {
                $item->stock_velocity = 'Fast';
            } elseif ($item->total_sold > 50) {
                $item->stock_velocity = 'Normal';
            } else {
                $item->stock_velocity = 'Slow';
            }
            return $item;
        });


        return response()->json($data);
    }
}
