<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PickupController;
use App\Http\Controllers\SupplierOrderController;
use App\Http\Controllers\SupplierReceiptController;
use App\Http\Controllers\DeplacementController;
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('deplacements/print/{id}', [DeplacementController::class, 'generatePdf']);
Route::get('deplacements/inventory/{id}', [DeplacementController::class, 'inventoryPdf']);
Route::get('supplier_receipts/print/{id}', [SupplierReceiptController::class, 'generatePdf']);
Route::get('supplier_orders/print/{id}', [SupplierOrderController::class, 'generatePdf']);
Route::get('orders/print/{id}', [OrderController::class, 'generatePdf']);
Route::get('pickups/print/{id}', [PickupController::class, 'generatePdf']);

Route::get('/', function () {
    return view('welcome');
});
