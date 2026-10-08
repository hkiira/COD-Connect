<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AfraShippingController;
use App\Http\Controllers\OrderWorkspaceController;

use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ReviewQuestionController;
use App\Http\Controllers\{
    CatalogToolsController,
    RoleController,
    OrderStatusController,
    UserController,
    PhoneController,
    RegionController,
    CountryController,
    CityController,
    AccountController,
    AttributeController,
    CategoryController,
    ProductController,
    PhoneTypesController,
    AddressController,
    CarrierController,
    SupplierController,
    PaymentTypeController,
    PaymentMethodController,
    ChargeTypeController,
    ChargeController,
    AccountCarrierCity,
    TypeAttributeController,
    BrandController,
    SourceController,
    DeliveryMenController,
    SupplierOrderController,
    SupplierReceiptController,
    OfferController,
    VariationAttributesController,
    CustomerController,
    CommentController,
    SubCommentController,
    OrderController,
    PermissionController,
    ImageController,
    FilterController,
    SectorController,
    WarehouseNatureController,
    WarehouseTypeController,
    WarehouseController,
    PartitionController,
    RayController,
    TaxonomyController,
    TypeTaxonomyController,
    DefaultCodeController,
    RestoreController,
    OfferTypeController,
    MeasurementController,
    PVAPackController,
    MouvementTypeController,
    DeplacementController,
    ChargementController,
    TransfertController,
    ReturnController,
    CustomerTypeController,
    CommissionTypeController,
    CommissionController,
    AccountCarrierController,
    ImageTypeController,
    RoleTypeController,
    PermissionTypeController,
    PickupController,
    TransactionController,
    ShipmentController,
    ShipmentTypeController,
    TransactionTypeController,
    ProductTypeController,
    PdfController,
    ExitslipController,
    InventoryController,
    ReceiptController,
    ImportController,
    CathedisController,
    AsapDeliveryController,
    PaymentController,
    SalaryController,
    BonusController,
    CompensationableController,
    ExpenseTypeController,
    ExpenseController,
    DashboardController,
    OldApiController,
    OldSysController,
    PVAController,
    SynchronisationController,
    SpeedafController,
    SpeedafwController,
    AfraDeliveryController,
    StockController,
    OverviewController,
    ScrapController,
    MouvementController,
    AnalyticsController,
    AsapDeliveryWebhookController,
    NextController
};
use App\Http\Controllers\API\RegisterController;
use App\Models\ExpenseType;
use App\Services\AsapDeliveryService;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });
Route::post('register_new_account', [RegisterController::class, 'register_new_account']);
Route::post('login', [RegisterController::class, 'login'])->middleware('throttle:login')->name('login');
Route::get('filterselect/wp-cities', [FilterController::class, 'cities']);
Route::get('webhooks/asap-delivery', [AsapDeliveryWebhookController::class, 'handle'])->middleware('asap.webhook');
// Route::resource('PhoneTypes', PhoneTypesController::class);

Route::middleware(['VerifyDomain', 'auth.optional'])->group(function () {
    Route::get('inventory', [StockController::class, 'index']);
});

Route::middleware(['auth:api', 'VerifyDomain'])->group(function () {
    Route::get('returns/print/{id}', [ReturnController::class, 'generatePdf']);
    Route::get('returns/inventory/{id}', [ReturnController::class, 'inventoryPdf']);
    Route::get('receipts/print/{id}', [ReceiptController::class, 'generatePdf']);
    Route::get('receipts/inventory/{id}', [ReceiptController::class, 'inventoryPdf']);
    Route::get('exitslips/print/{id}', [ExitslipController::class, 'generatePdf']);
    Route::get('exitslips/inventory/{id}', [ExitslipController::class, 'inventoryPdf']);
    Route::get('deplacements/print/{id}', [DeplacementController::class, 'generatePdf']);
    Route::get('deplacements/inventory/{id}', [DeplacementController::class, 'inventoryPdf']);
    Route::get('supplier_receipts/print/{id}', [SupplierReceiptController::class, 'generatePdf']);
    Route::get('supplier_orders/print/{id}', [SupplierOrderController::class, 'generatePdf']);
    Route::post('orders/{inTransitOrderId}/swap-delivery/{newOrderId}', [OrderController::class, 'swapDelivery']);
    Route::get('orders/print/{id}', [OrderController::class, 'generatePdf']);
    Route::get('pickups/tickets/{id}', [PickupController::class, 'generateTickets']);
    Route::get('pickups/print/{id}', [PickupController::class, 'generatePdf']);

    // ASAP Delivery test endpoints for Postman
    Route::get('asapdelivery/cities', function (AsapDeliveryService $service) {
        return response()->json($service->getCities());
    });

    Route::get('asapdelivery/track/{code}', function (AsapDeliveryService $service, string $code) {
        return response()->json($service->trackParcel($code));
    });

    Route::post('asapdelivery/add', function (AsapDeliveryService $service, Request $request) {
        $data = $request->only([
            'fullname',
            'phone',
            'city',
            'address',
            'price',
            'product',
            'qty',
            'note',
            'code2',
            'change',
            'openpackage',
        ]);

        return response()->json(['code' => $service->addParcel($data)]);
    });

    Route::get('asapdelivery/list', function (AsapDeliveryService $service) {
        return response()->json($service->listParcels());
    });

    Route::post('asapdelivery/update-status', function (AsapDeliveryService $service, Request $request) {
        $payload = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
            'datereported' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        return response()->json([
            'success' => $service->updateParcelStatus(
                $payload['code'],
                $payload['state'],
                $payload['datereported'] ?? null,
                $payload['note'] ?? null
            ),
        ]);
    });

    Route::resource('expenses', ExpenseController::class);
    Route::resource('expense_types', ExpenseTypeController::class);
    Route::resource('exitslips', ExitslipController::class);
    Route::resource('countries', CountryController::class);
    Route::resource('inventories', InventoryController::class);
    Route::resource('regions', RegionController::class);
    Route::resource('cities', CityController::class);
    Route::resource('sectors', SectorController::class);
    Route::resource('permissions', PermissionController::class);
    Route::resource('roles', RoleController::class);
    Route::resource('sources', SourceController::class);
    Route::resource('brands', BrandController::class);
    Route::resource('images', ImageController::class);
    Route::resource('warehouse_types', WarehouseTypeController::class);
    Route::resource('warehouse_natures', WarehouseNatureController::class);
    Route::resource('type_attributes', TypeAttributeController::class);
    Route::resource('type_taxonomies', TypeTaxonomyController::class);
    Route::resource('taxonomies', TaxonomyController::class);
    Route::resource('attributes', AttributeController::class);
    Route::resource('categories', CategoryController::class);
    Route::resource('type_phones', PhoneTypesController::class);
    Route::resource('suppliers', SupplierController::class);
    Route::resource('phones', PhoneController::class);
    Route::resource('addresses', AddressController::class);
    Route::resource('warehouses', WarehouseController::class);
    Route::resource('partitions', PartitionController::class);
    Route::resource('rays', RayController::class);
    Route::get('offers/create', [OfferController::class, 'createData']);
    Route::resource('offers', OfferController::class)->except(['create']);
    Route::post('products/{id}/variation-images', [ProductController::class, 'updateVariationImages']);
    Route::resource('products', ProductController::class);
    Route::resource('variation_attributes', VariationAttributesController::class);
    Route::resource('pvas', PVAController::class);
    Route::resource('users', UserController::class);
    Route::resource('accounts', AccountController::class);
    Route::resource('compensationables', CompensationableController::class);
    Route::resource('default_codes', DefaultCodeController::class);
    Route::resource('measurements', MeasurementController::class);
    Route::resource('type_offers', OfferTypeController::class);
    Route::resource('type_products', ProductTypeController::class);
    Route::resource('pva_packs', PVAPackController::class);
    Route::resource('supplier_orders', SupplierOrderController::class);
    Route::resource('supplier_receipts', SupplierReceiptController::class);
    Route::resource('type_mouvements', MouvementTypeController::class);
    Route::get('mouvements', [MouvementController::class, 'index']);
    Route::resource('deplacements', DeplacementController::class);
    Route::resource('chargements', ChargementController::class);
    Route::resource('tranferts', TransfertController::class);
    Route::resource('returns', ReturnController::class);
    Route::resource('customer_types', CustomerTypeController::class);

    // CRM Customer Extensions
    Route::post('customers/merge', [CustomerController::class, 'merge']);
    Route::post('customers/{id}/log-call', [CustomerController::class, 'logCall']);
    Route::post('customers/{id}/toggle-blacklist', [CustomerController::class, 'toggleBlacklist']);
    Route::get('customers/{id}/timeline', [CustomerController::class, 'timeline']);
    Route::get('customers/counts', [CustomerController::class, 'counts']);

    Route::apiResource('customers', CustomerController::class);
    Route::resource('payment_types', PaymentTypeController::class);
    Route::resource('payment_methods', PaymentMethodController::class);
    Route::resource('carriers', CarrierController::class);
    Route::resource('account_carriers', AccountCarrierController::class);
    Route::resource('commission_types', CommissionTypeController::class);
    Route::resource('commissions', CommissionController::class);
    Route::resource('salaries', SalaryController::class);
    Route::resource('bonuses', BonusController::class);
    Route::get('orders/counts', [OrderController::class, 'counts']);
    // order workspaces: queue counters, call log, assignment, focus mode (declared before the resource)
    Route::get('orders/queues/counts', [OrderWorkspaceController::class, 'queueCounts']);
    Route::get('orders/agents', [OrderWorkspaceController::class, 'agents']);
    Route::post('orders/assign', [OrderWorkspaceController::class, 'assign']);
    Route::post('orders/assign/auto', [OrderWorkspaceController::class, 'autoAssign']);
    Route::post('orders/queue/next', [OrderWorkspaceController::class, 'next']);
    Route::post('orders/{id}/release', [OrderWorkspaceController::class, 'release']);
    Route::get('orders/{id}/calls', [OrderWorkspaceController::class, 'calls']);
    Route::post('orders/{id}/calls', [OrderWorkspaceController::class, 'logCall']);
    // no GET orders/{id}: the details screen reads orders/{id}/edit
    Route::resource('orders', OrderController::class)->except(['show']);
    Route::post('orders/{id}/sync-sheet', [OrderController::class, 'syncSheet']);
    Route::post('orders/count-by-phones', [OrderController::class, 'countByPhones']);
    Route::post('orders/exchange', [OrderController::class, 'createExchange']);
    Route::resource('order_statuses', OrderStatusController::class);
    Route::resource('comments', CommentController::class);
    Route::resource('subcomments', SubCommentController::class);
    Route::resource('image_types', ImageTypeController::class);
    Route::resource('role_types', RoleTypeController::class);
    Route::resource('permission_types', PermissionTypeController::class);
    Route::resource('pickups', PickupController::class);
    Route::resource('transactions', TransactionController::class);
    Route::resource('shipments', ShipmentController::class);
    Route::get('shipments/print/{id}', [ShipmentController::class, 'printShipment']);
    Route::get('shipments/print/{id}/pdf', [ShipmentController::class, 'printShipmentPdf']);
    Route::resource('shipment_types', ShipmentTypeController::class);
    Route::resource('transaction_types', TransactionTypeController::class);
    Route::resource('payments', PaymentController::class);
    Route::get('dashboard', [DashboardController::class, 'dashboard']);
    Route::resource('dashboards', DashboardController::class);
    Route::put('restore/{entity}/{id}', [RestoreController::class, 'restore'])
        ->where('entity', '.*')
        ->where('id', '[0-9]+')
        ->name('restore');
    Route::get('import/{entity}/{id?}', [ImportController::class, 'import']);
    Route::get('old/{entity}/{id?}', [OldApiController::class, 'old']);
    Route::post('import/{entity}/{id?}', [ImportController::class, 'import']);
    Route::get('cathedis/{entity}/{id?}/{type?}', [CathedisController::class, 'rest']);
    Route::post('cathedis/{entity}/{id?}/{type?}', [CathedisController::class, 'rest']);
    Route::get('asap/{entity}/{id?}/{type?}', [AsapDeliveryController::class, 'rest']);
    Route::post('asap/{entity}/{id?}/{type?}', [AsapDeliveryController::class, 'rest']);
    Route::post('register_new_user', [RegisterController::class, 'register_new_user']);
    Route::get('filterselect/{model}/{id?}', [FilterController::class, 'filterselect']);
    // WooCommerce control panel: the stores of the account (several websites per account)
    Route::get('wc/stores', [\App\Http\Controllers\WooCommerceStoreController::class, 'index']);
    Route::post('wc/stores', [\App\Http\Controllers\WooCommerceStoreController::class, 'store']);
    Route::put('wc/stores/{id}', [\App\Http\Controllers\WooCommerceStoreController::class, 'update']);
    Route::delete('wc/stores/{id}', [\App\Http\Controllers\WooCommerceStoreController::class, 'destroy']);
    Route::post('wc/stores/{id}/test', [\App\Http\Controllers\WooCommerceStoreController::class, 'test']);
    Route::get('wc/stores/{id}/overview', [\App\Http\Controllers\WooCommerceStoreController::class, 'overview']);
    Route::get('wc/stores/{id}/products', [\App\Http\Controllers\WooCommerceProductController::class, 'index']);
    Route::get('wc/stores/{id}/products/{productId}/variations', [\App\Http\Controllers\WooCommerceProductController::class, 'variations']);
    Route::post('wc/stores/{id}/links', [\App\Http\Controllers\WooCommerceProductController::class, 'link']);
    Route::delete('wc/stores/{id}/links/{linkId}', [\App\Http\Controllers\WooCommerceProductController::class, 'unlink']);
    Route::post('wc/stores/{id}/auto-match', [\App\Http\Controllers\WooCommerceProductController::class, 'autoMatch']);
    Route::get('wc/stores/{id}/orders', [\App\Http\Controllers\WooCommerceOrderController::class, 'listForStore']);
    Route::get('wc/stores/{id}/status-mappings', [\App\Http\Controllers\WooCommerceStatusController::class, 'index']);
    Route::put('wc/stores/{id}/status-mappings', [\App\Http\Controllers\WooCommerceStatusController::class, 'update']);
    Route::post('wc/stores/{id}/orders/push-status', [\App\Http\Controllers\WooCommerceStatusController::class, 'pushMany']);
    Route::post('wc/stores/{id}/orders/{wcOrderId}/push-status', [\App\Http\Controllers\WooCommerceStatusController::class, 'pushOne']);
    Route::get('wc/stores/{id}/logs', [\App\Http\Controllers\WooCommerceLogController::class, 'index']);
    Route::post('wc/stores/{id}/logs/{logId}/retry', [\App\Http\Controllers\WooCommerceLogController::class, 'retry']);
    Route::post('wc/stores/{id}/orders/import', [\App\Http\Controllers\WooCommerceOrderController::class, 'importForStore']);
    Route::get('wc/stores/{id}/orders/{wcOrderId}', [\App\Http\Controllers\WooCommerceOrderController::class, 'showForStore']);
    // WooCommerce Order Management (new dedicated controller)
    Route::get('wc-orders', [\App\Http\Controllers\WooCommerceOrderController::class, 'getOrdersByStatus']);
    Route::post('wc-orders/import', [\App\Http\Controllers\WooCommerceOrderController::class, 'importOrders']);
    Route::post('wc-orders/sync-product', [\App\Http\Controllers\WooCommerceOrderController::class, 'syncProduct']);
    Route::get('wc-orders/{id}', [\App\Http\Controllers\WooCommerceOrderController::class, 'showOrder']);
    Route::get('oldsys/{model}/{id?}', [OldSysController::class, 'rest']);
    Route::post('oldsys/{model}/{id?}', [OldSysController::class, 'rest']);
    Route::post('filterselect/{model}/{id?}', [FilterController::class, 'filterselect']);
    Route::resource('customer', CustomerController::class);
    Route::resource('brand_source', SourceController::class);
    Route::resource('delivery_men', DeliveryMenController::class);
    Route::post('synchronisation/{entity}/{id?}/{type?}', [SynchronisationController::class, 'rest']);
    Route::get('synchronisation/{entity}/{id?}/{type?}', [SynchronisationController::class, 'rest']);
    Route::get('scrap/{entity}/{id?}/{type?}', [ScrapController::class, 'rest']);
    Route::post('scrap/{entity}/{id?}/{type?}', [ScrapController::class, 'rest']);
    Route::get('overviews/sales', [OverviewController::class, 'sales']);
    Route::get('overviews/logistics', [OverviewController::class, 'logistics']);
    Route::get('overviews/logistics/exceptions', [OverviewController::class, 'logisticsExceptions']);
    Route::get('overviews/logistics/cities', [OverviewController::class, 'logisticsCities']);
    Route::get('overviews/logistics/billing', [OverviewController::class, 'logisticsBilling']);
    Route::get('overviews/procurement', [OverviewController::class, 'procurement']);
    Route::get('overviews/catalog', [OverviewController::class, 'catalog']);
    Route::get('overviews/catalog/quality', [OverviewController::class, 'catalogQuality']);

    Route::prefix('catalog-tools')->group(function () {
        Route::get('stock-alerts', [CatalogToolsController::class, 'stockAlerts']);
        Route::get('stock-reconciliation', [CatalogToolsController::class, 'stockReconciliation']);
        Route::post('stock-adjust', [CatalogToolsController::class, 'stockAdjust']);
        Route::get('export', [CatalogToolsController::class, 'export']);
        Route::post('import', [CatalogToolsController::class, 'import']);
        Route::post('bulk-update', [CatalogToolsController::class, 'bulkUpdate']);
    });
    Route::get('overviews/finance', [OverviewController::class, 'finance']);
    Route::get('overviews/administration', [OverviewController::class, 'administration']);
    // Speedafv public API
    Route::post('speedaf/track-order', [SpeedafController::class, 'trackOrder']);
    // CSP public passthrough
    Route::post('speedaf/track-csp', [SpeedafController::class, 'trackCsp']);
    // Create order (Speedaf open-api encrypted endpoint)
    Route::post('speedaf/create-order', [SpeedafController::class, 'createOrder']);
    Route::post('speedaf/export/{id}', [SpeedafController::class, 'exportPickupOrders']);
    Route::post('speedaf/import_orders', [SpeedafController::class, 'importOrders']);
    Route::post('afra/export/{id}', [AfraDeliveryController::class, 'exportPickupOrders']);

    Route::get('afra-shipping/account', [AfraShippingController::class, 'account']);
    Route::put('afra-shipping/account', [AfraShippingController::class, 'saveAccount']);
    Route::post('afra-shipping/login', [AfraShippingController::class, 'login']);
    Route::get('afra-shipping/orders', [AfraShippingController::class, 'orders']);
    Route::get('afra-shipping/cities', [AfraShippingController::class, 'cities']);
    Route::put('afra-shipping/cities', [AfraShippingController::class, 'mapCity']);
    Route::post('afra-shipping/cities/auto', [AfraShippingController::class, 'autoLinkCities']);
    Route::get('afra-shipping/cities/changes', [AfraShippingController::class, 'cityChanges']);
    Route::post('afra-shipping/cities/check', [AfraShippingController::class, 'checkCities']);
    Route::post('afra-shipping/cities/changes/{id}/resolve', [AfraShippingController::class, 'resolveCityChange']);
    Route::post('afra-shipping/cities/changes/{id}/apply-price', [AfraShippingController::class, 'applyCityPrice']);

    Route::get('notifications', [\App\Http\Controllers\NotificationController::class, 'index']);
    Route::post('notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
    Route::post('notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markRead']);
    Route::get('afra-shipping/statuses', [AfraShippingController::class, 'statuses']);
    Route::put('afra-shipping/statuses', [AfraShippingController::class, 'mapStatus']);
    Route::post('afra-shipping/pickups/{id}/sync', [AfraShippingController::class, 'syncPickup']);
    Route::post('afra-shipping/statuses/sync', [AfraShippingController::class, 'syncStatusesNow']);
    Route::get('afra-shipping/runs/{id}', [AfraShippingController::class, 'run']);
    Route::post('afra-shipping/orders/{id}/return/retry', [AfraShippingController::class, 'retryReturn']);
    Route::post('afra-shipping/orders/{id}/resend', [AfraShippingController::class, 'resendOrder']);
    Route::post('afra-shipping/orders/match-codes', [AfraShippingController::class, 'matchCodes']);
    Route::get('afra-shipping/tracking', [AfraShippingController::class, 'tracking']);
    Route::get('afra-shipping/returns', [AfraShippingController::class, 'returns']);
    Route::get('afra-shipping/returns/lookup', [AfraShippingController::class, 'lookupReturn']);
    Route::post('afra-shipping/returns/receive', [AfraShippingController::class, 'receiveReturns']);
    Route::post('afra-shipping/payments/reconcile', [AfraShippingController::class, 'reconcilePayment']);
    Route::post('afra-shipping/payments', [AfraShippingController::class, 'createPayment']);
    // GET endpoint to fetch the questions for building the form
    Route::get('/orders/{order}/reviews/questions', [ReviewController::class, 'index']);

    // POST endpoint to submit the review form
    Route::post('/orders/{order}/reviews', [ReviewController::class, 'store']);
    Route::apiResource('review-questions', ReviewQuestionController::class);
    Route::get('/analytics/kpi', [AnalyticsController::class, 'kpi']);
    Route::get('/analytics/sales-vs-refusals', [AnalyticsController::class, 'salesVsRefusals']);
    Route::get('/analytics/size-matrix', [AnalyticsController::class, 'sizeMatrix']);
    Route::get('/analytics/geographic', [AnalyticsController::class, 'geographic']);
    Route::get('/analytics/refusal-reasons', [AnalyticsController::class, 'refusalReasons']);
    Route::get('/analytics/acquisition-sources', [AnalyticsController::class, 'acquisitionSources']);
    Route::get('/analytics/product-performance', [AnalyticsController::class, 'productPerformance']);

    // NextController API routes
    Route::post('next/create_order', [NextController::class, 'create_order']);
    Route::post('next/update_order', [NextController::class, 'update_order']);
    Route::post('next/cancel_order', [NextController::class, 'cancel_order']);
    Route::get('next/get_products', [NextController::class, 'get_products']);
    Route::get('next/get_product', [NextController::class, 'get_product']);
    Route::get('next/getProduct', [NextController::class, 'getProduct']);
    Route::get('next/product_attributes/{id?}', [NextController::class, 'product_attributes']);
    Route::get('next/get_product_attributes/{id?}', [NextController::class, 'product_attributes']);
    Route::get('next/get_customer', [NextController::class, 'get_customer']);
    Route::get('asap/asap-history/{start}/{end}', [AsapDeliveryController::class, 'asapHistory']);
    Route::get('asap/raw-html/{id}', [AsapDeliveryController::class, 'rawHtml']);

    // Order Management
    // Route::post('/speedafw/orders/create', [SpeedafwController::class, 'createOrder']);
    // Route::post('/speedafw/orders/batch-create', [SpeedafwController::class, 'batchCreateOrders']);
    // Route::post('/speedafw/orders/cancel', [SpeedafwController::class, 'cancelOrder']);
    // Route::post('/speedafw/orders/import-create', [SpeedafwController::class, 'importAndCreateOrders']);

    // // Tracking
    // Route::post('/speedafw/track', [SpeedafwController::class, 'trackOrder']);
    // Route::get('/speedafw/track/{trackingNumber}', [SpeedafwController::class, 'trackOrder']);

    // // Sorting Code Services
    // Route::post('/speedafw/sorting-code/waybill', [SpeedafwController::class, 'getSortingCodeByWaybill']);
    // Route::post('/speedafw/sorting-code/address', [SpeedafwController::class, 'getSortingCodeByAddress']);

    // // Label Printing
    // Route::post('/speedafw/print-label', [SpeedafwController::class, 'printLabel']);

    // Overview Endpoints

    Route::get('/speedafw/debug/api-connection', [SpeedafwController::class, 'testApiConnection']);

});

// Route::post('deliveryMen', [DeliveryMenController::class, 'index']);
// Route::post('old_sys', [OldSysController::class, 'index']);
// Route::get('synchronisation/{entity}/{id?}/{type?}', [SynchronisationController::class, 'rest']);
// Route::post('import', [ImportController::class, 'import']);
// Route::post('importInventories', [ImportController::class, 'importInventories']);
// Route::post('checkcities', [SynchronisationController::class, 'checkCities']);
Route::prefix('analytics')->middleware('auth:sanctum')->group(function () {
});
