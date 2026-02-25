<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SpeedafwController;

/*
|--------------------------------------------------------------------------
| Speedaf WooCommerce API Routes
|--------------------------------------------------------------------------
|
| Here are the routes for integrating with Speedaf Express services
| compatible with WooCommerce extension functionality
|
*/

Route::prefix('api/speedafw')->group(function () {
    
    // Order Management
    Route::post('/orders/create', [SpeedafwController::class, 'createOrder']);
    Route::post('/orders/batch-create', [SpeedafwController::class, 'batchCreateOrders']);
    Route::post('/orders/cancel', [SpeedafwController::class, 'cancelOrder']);
    Route::post('/orders/import-create', [SpeedafwController::class, 'importAndCreateOrders']);
    
    // Tracking
    Route::post('/track', [SpeedafwController::class, 'trackOrder']);
    Route::get('/track/{trackingNumber}', [SpeedafwController::class, 'trackOrder']);
    
    // Sorting Code Services
    Route::post('/sorting-code/waybill', [SpeedafwController::class, 'getSortingCodeByWaybill']);
    Route::post('/sorting-code/address', [SpeedafwController::class, 'getSortingCodeByAddress']);
    
    // Label Printing
    Route::post('/print-label', [SpeedafwController::class, 'printLabel']);
    
    // Debug Endpoints
    Route::get('/debug/encryption', [SpeedafwController::class, 'testEncryption']);
    Route::get('/debug/api-connection', [SpeedafwController::class, 'testApiConnection']);
    
});