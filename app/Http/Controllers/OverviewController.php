<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use App\Models\Order;
use App\Models\Pickup;
use App\Models\SupplierOrder;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Brand;
use App\Models\Source;
use App\Models\Mouvement;
use App\Models\Supplier;
use App\Models\SupplierReceipt;

class OverviewController extends Controller
{
    private const CACHE_TTL = 300; // 5 minutes

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
            ];
        });
    }

    public function logistics(Request $request)
    {
        return $this->buildResponse($request, 'logistics', function ($dates, $accountId) {
            return [
                'summary'               => $this->getLogisticsSummary($dates, $accountId),
                'pickups_by_status'     => $this->getPickupsByStatus($dates, $accountId),
                'deliveries_by_carrier' => $this->getDeliveriesByCarrier($dates, $accountId),
                'recent_pickups'        => $this->getRecentPickups($dates, $accountId),
            ];
        });
    }

    public function procurement(Request $request)
    {
        return $this->buildResponse($request, 'procurement', function ($dates, $accountId) {
            return [
                'summary'                   => $this->getProcurementSummary($dates, $accountId),
                'purchase_orders_by_status' => $this->getPurchaseOrdersByStatus($dates, $accountId),
                'stock_by_warehouse'        => $this->getStockByWarehouse($accountId),
                'recent_movements'          => $this->getRecentMovements($dates, $accountId),
                'low_stock_alerts'          => $this->getLowStockAlerts($accountId, 10),
            ];
        });
    }

    public function catalog(Request $request)
    {
        return $this->buildResponse($request, 'catalog', function ($dates, $accountId) {
            return [
                'summary'           => $this->getCatalogSummary($dates, $accountId),
                'products_by_type'  => $this->getProductsByType($accountId),
                'products_by_brand' => $this->getProductsByBrand($accountId),
                'recent_products'   => $this->getRecentProducts($dates, $accountId),
                'top_categories'    => $this->getTopCategories($accountId),
            ];
        });
    }

    public function finance(Request $request)
    {
        return $this->buildResponse($request, 'finance', function ($dates, $accountId) {
            return [
                'summary'               => $this->getFinanceSummary($dates, $accountId),
                'transactions_by_month' => $this->getTransactionsByMonth($dates, $accountId),
                'expenses_breakdown'    => $this->getExpensesBreakdown($dates, $accountId),
                'recent_transactions'   => $this->getRecentTransactions($dates, $accountId),
                'salary_by_user'        => $this->getSalaryByUser($dates, $accountId),
            ];
        });
    }

    public function administration(Request $request)
    {
        return $this->buildResponse($request, 'administration', function ($dates, $accountId) {
            return [
                'summary'        => $this->getAdministrationSummary($dates, $accountId),
                'users_by_role'  => $this->getUsersByRole(),
                'recent_users'   => $this->getRecentUsers($dates),
                'top_performers' => $this->getTopPerformers($dates, $accountId),
            ];
        });
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
            'total_revenue'   => $this->formatMetric($currentRevenue, $prevRevenue, true),
            'total_orders'    => $this->formatMetric($currentOrders, $prevOrders),
            'avg_order_value' => $this->formatMetric($currentAvgOrderValue, $prevAvgOrderValue, true),
            'total_offers'    => $this->formatMetric($currentOffers, $prevOffers),
        ];
    }
    
    private function getRevenueQuery($dates, $accountId)
    {
        return DB::table('order_pva')
            ->join('orders', 'order_pva.order_id', '=', 'orders.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates)
            ->whereNull('orders.deleted_at')
            ->whereNotIn('order_pva.order_status_id', [2, 3]);
    }
    
    private function getOrdersByStatus($dates, $accountId)
    {
        return DB::table('orders')
            ->join('order_statuses', 'orders.order_status_id', '=', 'order_statuses.id')
            ->where('orders.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->whereNull('orders.deleted_at')
            ->select('order_statuses.title as status', DB::raw('count(*) as count'))
            ->groupBy('order_statuses.title')
            ->get();
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
                'status'     => $order->orderStatus?->title ?? 'N/A',
                'created_at' => $order->created_at->toDateString(),
            ]);
    }

    //endregion

    //region Data Fetching (Logistics)
    private function getLogisticsSummary($dates, $accountId)
    {
        $currentPickups = Pickup::whereHas('warehouse', fn($q) => $q->where('account_id', $accountId))
                                  ->whereBetween('created_at', $dates['current'])->count();
        $prevPickups = Pickup::whereHas('warehouse', fn($q) => $q->where('account_id', $accountId))
                               ->whereBetween('created_at', $dates['previous'])->count();

        $pendingPickups = Pickup::whereHas('warehouse', fn($q) => $q->where('account_id', $accountId))
                                  ->whereBetween('created_at', $dates['current'])->where('statut', 0)->count();

        $totalReturns = Mouvement::whereHas('accountUser', fn($q) => $q->where('account_id', $accountId))
                                   ->where('mouvement_type_id', 3)->whereBetween('created_at', $dates['current'])->count();

        $carrierPayments = Transaction::whereHas('accountUser', fn($q) => $q->where('account_id', $accountId))
                                       ->whereBetween('created_at', $dates['current'])
                                       ->where('transaction_type', 'App\Models\Carrier')->sum('amount');
        
        return [
            'total_pickups'          => $this->formatMetric($currentPickups, $prevPickups),
            'pending_pickups'        => $this->formatMetric($pendingPickups, 0),
            'total_returns'          => $this->formatMetric($totalReturns, 0),
            'total_payments_carrier' => $this->formatMetric($carrierPayments, 0, true),
        ];
    }
    
    private function getPickupsByStatus($dates, $accountId)
    {
        $statusMap = [0 => 'pending', 1 => 'collected', 2 => 'completed'];
        
        return DB::table('pickups')
            ->join('warehouses', 'pickups.warehouse_id', '=', 'warehouses.id')
            ->where('warehouses.account_id', $accountId)
            ->whereBetween('pickups.created_at', $dates['current'])->whereNull('pickups.deleted_at')
            ->select('pickups.statut as status', DB::raw('count(*) as count'))
            ->groupBy('pickups.statut')
            ->get()
            ->map(fn($item) => ['status' => $statusMap[$item->status] ?? 'unknown', 'count' => $item->count]);
    }
    
    private function getDeliveriesByCarrier($dates, $accountId)
    {
        return DB::table('carriers')
            ->leftJoin('pickups', 'carriers.id', '=', 'pickups.carrier_id')
            ->leftJoin('warehouses', 'pickups.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('orders', 'pickups.id', '=', 'orders.pickup_id')
            ->where('warehouses.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->select(
                'carriers.id as carrier_id', 'carriers.title as carrier_name',
                DB::raw('COUNT(CASE WHEN orders.order_status_id IN (7, 10) THEN 1 END) as total_delivered'),
                DB::raw('COUNT(CASE WHEN orders.order_status_id IN (11, 8, 3, 2) THEN 1 END) as total_returned')
            )
            ->groupBy('carriers.id', 'carriers.title')
            ->get()
            ->map(function ($item) {
                $total = $item->total_delivered + $item->total_returned;
                $item->delivery_rate = $total > 0 ? round(($item->total_delivered / $total) * 100, 2) : 0;
                return $item;
            });
    }

    private function getRecentPickups($dates, $accountId)
    {
        return Pickup::with('carrier')
            ->whereHas('warehouse', fn($q) => $q->where('account_id', $accountId))
            ->withCount('orders')
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($pickup) => [
                'id'           => $pickup->id,
                'code'         => $pickup->code,
                'carrier'      => $pickup->carrier?->title ?? 'N/A',
                'orders_count' => $pickup->orders_count,
                'status'       => [0 => 'pending', 1 => 'collected', 2 => 'completed'][$pickup->statut] ?? 'unknown',
                'created_at'   => $pickup->created_at->toDateString(),
            ]);
    }
    //endregion

    //region Data Fetching (Procurement)
    private function getProcurementSummary($dates, $accountId)
    {
        $currentSuppliers = Supplier::whereBetween('created_at', $dates['current'])->count();
        $prevSuppliers = Supplier::whereBetween('created_at', $dates['previous'])->count();

        $currentPOs = SupplierOrder::whereBetween('created_at', $dates['current'])->count();
        $prevPOs = SupplierOrder::whereBetween('created_at', $dates['previous'])->count();
        
        $pendingPOs = SupplierOrder::whereBetween('created_at', $dates['current'])->where('statut', 0)->count();
        
        $currentReceipts = SupplierReceipt::whereBetween('created_at', $dates['current'])->count();
        $prevReceipts = SupplierReceipt::whereBetween('created_at', $dates['previous'])->count();
        
        $inventoryValue = DB::table('warehouse_pva')
            ->join('product_variation_attribute', 'warehouse_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->join('products', 'product_variation_attribute.product_id', '=', 'products.id')
            ->join('offerables', function ($join) {
                $join->on('products.id', '=', 'offerables.offerable_id')
                    ->where('offerables.offerable_type', 'App\Models\Product');
            })
            ->join('offers', function ($join) {
                $join->on('offerables.offer_id', '=', 'offers.id')
                    ->where('offers.offer_type_id', 1)
                    ->where('offers.statut', 1);
            })
            ->sum(DB::raw('warehouse_pva.quantity * offers.price'));

        return [
            'total_suppliers'       => $this->formatMetric(Supplier::count(), 0),
            'total_purchase_orders' => $this->formatMetric($currentPOs, $prevPOs),
            'pending_orders'        => $this->formatMetric($pendingPOs, 0),
            'total_receipts'        => $this->formatMetric($currentReceipts, $prevReceipts),
            'total_inventory_value' => $this->formatMetric($inventoryValue, 0, true),
        ];
    }
    
    private function getPurchaseOrdersByStatus($dates, $accountId)
    {
        $statusMap = [0 => 'draft', 1 => 'confirmed', 2 => 'received', 3 => 'cancelled'];
        
        return DB::table('supplier_orders')
            ->whereBetween('created_at', $dates['current'])->whereNull('deleted_at')
            ->select('statut as status', DB::raw('count(*) as count'))
            ->groupBy('statut')
            ->get()
            ->map(fn($item) => ['status' => $statusMap[$item->status] ?? 'unknown', 'count' => $item->count]);
    }
    
    private function getStockByWarehouse($accountId)
    {
        return DB::table('warehouses')
            ->where('account_id', $accountId)->whereNull('deleted_at')
            ->leftJoin('warehouse_pva', 'warehouses.id', '=', 'warehouse_pva.warehouse_id')
            ->select(
                'warehouses.id as warehouse_id', 'warehouses.title as warehouse_name',
                DB::raw('SUM(warehouse_pva.quantity) as total_stock'),
                DB::raw('COUNT(DISTINCT warehouse_pva.product_variation_attribute_id) as distinct_products')
            )
            ->groupBy('warehouses.id', 'warehouses.title')
            ->get();
    }
    
    private function getRecentMovements($dates, $accountId)
    {
        return Mouvement::with(['toWarehouse'])
            ->whereHas('accountUser', fn($q) => $q->where('account_id', $accountId))
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($mvt) => [
                'id'         => $mvt->id,
                'type'       => [1 => 'in', 2 => 'out', 3 => 'return'][$mvt->mouvement_type_id] ?? 'movement',
                'quantity'   => DB::table('mouvement_pva')->where('mouvement_id', $mvt->id)->sum('quantity'), // Can be optimized
                'warehouse'  => $mvt->toWarehouse?->title ?? 'N/A',
                'created_at' => $mvt->created_at->toDateString(),
            ]);
    }

    private function getLowStockAlerts($accountId, $threshold = 10)
    {
        return DB::table('warehouse_pva')
            ->join('warehouses', 'warehouse_pva.warehouse_id', '=', 'warehouses.id')
            ->join('product_variation_attribute as pva', 'warehouse_pva.product_variation_attribute_id', '=', 'pva.id')
            ->join('products', 'pva.product_id', '=', 'products.id')
            ->where('warehouses.account_id', $accountId)
            ->where('warehouse_pva.quantity', '<', $threshold)
            ->select('products.id', 'products.title as product_name', 'warehouses.title as warehouse', 'warehouse_pva.quantity as current_stock')
            ->orderBy('warehouse_pva.quantity')
            ->limit(5)
            ->get();
    }
    //endregion

    //region Data Fetching (Catalog)
    private function getCatalogSummary($dates, $accountId)
    {
        $currentProducts = Product::whereBetween('created_at', $dates['current'])->count();
        $prevProducts = Product::whereBetween('created_at', $dates['previous'])->count();

        $activeProducts = Product::where('statut', 1)->count();
        
        $outOfStock = DB::table('products as p')
            ->leftJoin('product_variation_attribute as pva', 'p.id', '=', 'pva.product_id')
            ->leftJoin('warehouse_pva as wpva', 'pva.id', '=', 'wpva.product_variation_attribute_id')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                      ->from('warehouse_pva')
                      ->join('product_variation_attribute', 'warehouse_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
                      ->whereColumn('product_variation_attribute.product_id', 'p.id')
                      ->where('warehouse_pva.quantity', '>', 0);
            })
            ->count();
        
        return [
            'total_products'  => $this->formatMetric($currentProducts, $prevProducts),
            'active_products' => $this->formatMetric($activeProducts, 0),
            'total_brands'    => $this->formatMetric(Brand::count(), 0),
            'out_of_stock'    => $this->formatMetric($outOfStock, 0),
        ];
    }
    
    private function getProductsByType($accountId)
    {
        return DB::table('product_types')
            ->leftJoin('products', 'product_types.id', '=', 'products.product_type_id')
            ->select('product_types.title as type_name', DB::raw('count(products.id) as count'))
            ->groupBy('product_types.title')
            ->get();
    }
    
    private function getProductsByBrand($accountId)
    {
        return DB::table('brands')
            ->leftJoin('brand_source', 'brands.id', '=', 'brand_source.brand_id')
            ->leftJoin('product_brand_source', 'brand_source.id', '=', 'product_brand_source.brand_source_id')
            ->select('brands.title as brand_name', DB::raw('count(DISTINCT product_brand_source.product_id) as count'))
            ->groupBy('brands.title')
            ->get();
    }
    
    private function getRecentProducts($dates, $accountId)
    {
        return Product::with(['productType', 'price'])
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($product) => [
                'id'         => $product->id,
                'name'       => $product->title,
                'price'      => $product->price->first()?->price,
                'stock'      => $this->getProductStock($product->id),
                'status'     => $product->statut == 1 ? 'active' : 'inactive',
                'created_at' => $product->created_at->toDateString(),
            ]);
    }
    
    private function getProductStock($productId)
    {
        return DB::table('warehouse_pva')
            ->join('product_variation_attribute', 'warehouse_pva.product_variation_attribute_id', '=', 'product_variation_attribute.id')
            ->where('product_variation_attribute.product_id', $productId)
            ->sum('warehouse_pva.quantity');
    }
    
    private function getTopCategories($accountId)
    {
        return DB::table('taxonomies')
            ->join('taxonomy_product', 'taxonomies.id', '=', 'taxonomy_product.taxonomy_id')
            ->select('taxonomies.title as taxonomy_name', DB::raw('count(*) as products_count'))
            ->groupBy('taxonomies.title')
            ->orderByDesc('products_count')
            ->limit(5)
            ->get();
    }

    //endregion

    //region Data Fetching (Finance)
    private function getFinanceSummary($dates, $accountId)
    {
        $currentTransactions = Transaction::whereBetween('created_at', $dates['current'])->sum('amount');
        $prevTransactions = Transaction::whereBetween('created_at', $dates['previous'])->sum('amount');
        
        $currentSalaries = $this->getExpenseQuery($dates['current'], 'Salary')->sum(DB::raw('ABS(amount)'));
        $prevSalaries = $this->getExpenseQuery($dates['previous'], 'Salary')->sum(DB::raw('ABS(amount)'));
        
        $currentCommissions = $this->getExpenseQuery($dates['current'], 'Commission')->sum(DB::raw('ABS(amount)'));
        $prevCommissions = $this->getExpenseQuery($dates['previous'], 'Commission')->sum(DB::raw('ABS(amount)'));
        
        $netBalance = Transaction::whereBetween('created_at', $dates['current'])->sum('amount');

        return [
            'total_transactions_amount' => $this->formatMetric($currentTransactions, $prevTransactions, true),
            'total_salaries_paid'       => $this->formatMetric($currentSalaries, $prevSalaries, true),
            'total_commissions'         => $this->formatMetric($currentCommissions, $prevCommissions, true),
            'net_balance'               => $this->formatMetric($netBalance, 0, true),
        ];
    }
    
    private function getExpenseQuery($dates, $type)
    {
        return Transaction::whereBetween('created_at', $dates)
            ->where('amount', '<', 0)
            ->where('transaction_type', 'LIKE', "%{$type}%");
    }
    
    private function getTransactionsByMonth($dates, $accountId)
    {
        return DB::table('transactions')
            ->whereBetween('created_at', $dates['current'])
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income'),
                DB::raw('SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END) as expense')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }

    private function getExpensesBreakdown($dates, $accountId)
    {
        $totalExpense = Transaction::whereBetween('created_at', $dates['current'])->where('amount', '<', 0)->sum(DB::raw('ABS(amount)'));

        return DB::table('transactions')
            ->join('transaction_types', 'transactions.transaction_type_id', '=', 'transaction_types.id')
            ->whereBetween('transactions.created_at', $dates['current'])->where('transactions.amount', '<', 0)
            ->select('transaction_types.title as category', DB::raw('SUM(ABS(transactions.amount)) as amount'))
            ->groupBy('transaction_types.title')
            ->get()
            ->map(function ($item) use ($totalExpense) {
                $item->percentage = $totalExpense > 0 ? round(($item->amount / $totalExpense) * 100, 2) : 0;
                return $item;
            });
    }

    private function getRecentTransactions($dates, $accountId)
    {
        return Transaction::with(['transactionType'])
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($txn) => [
                'id'          => $txn->id,
                'description' => $txn->title,
                'amount'      => $txn->amount,
                'type'        => $txn->amount > 0 ? 'income' : 'expense',
                'status'      => $txn->statut == 1 ? 'completed' : 'pending',
                'created_at'  => $txn->created_at->toDateString(),
            ]);
    }
    
    private function getSalaryByUser($dates, $accountId)
    {
        return DB::table('transactions')
            ->join('account_user', 'transactions.account_user_id', '=', 'account_user.id')
            ->join('users', 'account_user.user_id', '=', 'users.id')
            ->where('account_user.account_id', $accountId)
            ->whereBetween('transactions.created_at', $dates['current'])
            ->select(
                'account_user.id as user_id', DB::raw("CONCAT(users.firstname, ' ', users.lastname) as user_name"),
                DB::raw('SUM(CASE WHEN transactions.transaction_type LIKE "%Salary%" THEN ABS(transactions.amount) ELSE 0 END) as total_salary'),
                DB::raw('SUM(CASE WHEN transactions.transaction_type LIKE "%Commission%" THEN ABS(transactions.amount) ELSE 0 END) as total_commission')
            )
            ->groupBy('account_user.id', 'users.firstname', 'users.lastname')
            ->get();
    }
    //endregion

    //region Data Fetching (Administration)
    private function getAdministrationSummary($dates, $accountId)
    {
        $currentUsers = User::whereBetween('created_at', $dates['current'])->count();
        $prevUsers = User::whereBetween('created_at', $dates['previous'])->count();
        
        $activeUsers = User::where('statut', 1)->count();
        
        $newThisMonth = User::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->count();
        
        return [
            'total_users'          => $this->formatMetric($currentUsers, $prevUsers),
            'active_users'         => $this->formatMetric($activeUsers, 0),
            'total_roles'          => $this->formatMetric(DB::table('roles')->count(), 0),
            'new_users_this_month' => $this->formatMetric($newThisMonth, 0),
        ];
    }
    
    private function getUsersByRole()
    {
        return DB::table('roles')
            ->leftJoin('model_has_roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->select('roles.name as role_name', DB::raw('count(model_has_roles.model_id) as count'))
            ->groupBy('roles.name')
            ->get();
    }
    
    private function getRecentUsers($dates)
    {
        return User::with('roles')
            ->whereBetween('created_at', $dates['current'])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn($user) => [
                'id'         => $user->id,
                'name'       => "{$user->firstname} {$user->lastname}",
                'email'      => $user->email,
                'role'       => $user->roles->first()?->name ?? 'N/A',
                'status'     => $user->statut == 1 ? 'active' : 'inactive',
                'created_at' => $user->created_at->toDateString(),
            ]);
    }

    private function getTopPerformers($dates, $accountId)
    {
        return DB::table('users')
            ->join('account_user', 'users.id', '=', 'account_user.user_id')
            ->join('account_user_order_status', 'account_user.id', '=', 'account_user_order_status.account_user_id')
            ->join('orders', 'account_user_order_status.order_id', '=', 'orders.id')
            ->leftJoin('order_pva', 'orders.id', '=', 'order_pva.order_id')
            ->where('account_user.account_id', $accountId)
            ->whereBetween('orders.created_at', $dates['current'])
            ->whereNotIn('orders.order_status_id', [2, 3])
            ->select(
                'account_user.id as user_id', DB::raw("CONCAT(users.firstname, ' ', users.lastname) as user_name"),
                DB::raw('COUNT(DISTINCT orders.id) as orders_count'),
                DB::raw('SUM(IFNULL(order_pva.price * order_pva.quantity, 0)) as revenue')
            )
            ->groupBy('account_user.id', 'users.firstname', 'users.lastname')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();
    }

    //region Helpers
    private function buildResponse(Request $request, $cacheKey, callable $dataCallback)
    {
        $dates = $this->getDateRanges($request);
        $accountId = getAccountUser()->account_id;
        
        $fullCacheKey = "overview_{$cacheKey}_{$accountId}_{$dates['current'][0]->toDateString()}_{$dates['current'][1]->toDateString()}";

        $data = Cache::remember($fullCacheKey, self::CACHE_TTL, fn() => $dataCallback($dates, $accountId));

        return response()->json(['data' => $data]);
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
