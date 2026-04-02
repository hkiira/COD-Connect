<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\{
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
    OrderPvaController,
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
    WoocommerceController,
    OldSysController,
    PVAController,
    SynchronisationController,
    OrderfirstController,
    SpeedafController,
    SpeedafwController,
    AfraDeliveryController,
    StockController
};
use App\Http\Controllers\API\RegisterController;
use App\Models\ExpenseType;

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
Route::post('login', [RegisterController::class, 'login']);
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
    Route::get('orders/print/{id}', [OrderController::class, 'generatePdf']);
    Route::get('pickups/tickets/{id}', [PickupController::class, 'generateTickets']);
    Route::get('pickups/print/{id}', [PickupController::class, 'generatePdf']);
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
    Route::resource('deplacements', DeplacementController::class);
    Route::resource('chargements', ChargementController::class);
    Route::resource('tranferts', TransfertController::class);
    Route::resource('returns', ReturnController::class);
    Route::resource('customer_types', CustomerTypeController::class);
    Route::resource('customers', CustomerController::class);
    Route::resource('payment_types', PaymentTypeController::class);
    Route::resource('payment_methods', PaymentMethodController::class);
    Route::resource('carriers', CarrierController::class);
    Route::resource('account_carriers', AccountCarrierController::class);
    Route::resource('commission_types', CommissionTypeController::class);
    Route::resource('commissions', CommissionController::class);
    Route::resource('salaries', SalaryController::class);
    Route::resource('bonuses', BonusController::class);
    Route::resource('orders', OrderController::class);
    Route::post('orders/count-by-phones', [OrderController::class, 'countByPhones']);
    Route::resource('orders_first', OrderfirstController::class);
    Route::resource('order_pvas', OrderPvaController::class);
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
    Route::get('woocommerce/{model}/{id?}', [WoocommerceController::class, 'rest']);
    Route::post('woocommerce/{model}/{id?}', [WoocommerceController::class, 'rest']);
    Route::get('oldsys/{model}/{id?}', [OldSysController::class, 'rest']);
    Route::post('oldsys/{model}/{id?}', [OldSysController::class, 'rest']);
    Route::post('filterselect/{model}/{id?}', [FilterController::class, 'filterselect']);
    Route::resource('customer', CustomerController::class);
    Route::resource('brand_source', SourceController::class);
    Route::resource('delivery_men', DeliveryMenController::class);
    Route::get('orders/test-total/{orderId}', [OrderController::class, 'testCalculateTotal']);
    Route::post('synchronisation/{entity}/{id?}/{type?}', [SynchronisationController::class, 'rest']);
    Route::get('synchronisation/{entity}/{id?}/{type?}', [SynchronisationController::class, 'rest']);
    // Speedaf public API
    Route::post('speedaf/track-order', [SpeedafController::class, 'trackOrder']);
    // CSP public passthrough
    Route::post('speedaf/track-csp', [SpeedafController::class, 'trackCsp']);
    // Create order (Speedaf open-api encrypted endpoint)
    Route::post('speedaf/create-order', [SpeedafController::class, 'createOrder']);
    Route::post('speedaf/export/{id}', [SpeedafController::class, 'exportPickupOrders']);
    Route::post('speedaf/import_orders', [SpeedafController::class, 'importOrders']);
    Route::post('afra/import_orders', [AfraDeliveryController::class, 'importOrders']);
    Route::post('afra/export/{id}', [AfraDeliveryController::class, 'exportPickupOrders']);

    Route::get('afradelivery/{entity}/{id?}/{type?}', [AfraDeliveryController::class, 'rest']);
    Route::post('afradelivery/{entity}/{id?}/{type?}', [AfraDeliveryController::class, 'rest']);
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
    
    // Route::get('/speedafw/debug/encryption', [SpeedafwController::class, 'testEncryption']);
    // Route::get('/speedafw/debug/api-connection', [SpeedafwController::class, 'testApiConnection']);
});
