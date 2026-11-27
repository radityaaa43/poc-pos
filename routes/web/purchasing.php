<?php

use App\Http\Controllers\Apps\GoodsReceivingController;
use App\Http\Controllers\Apps\PurchaseOrderController;
use App\Http\Controllers\Apps\StockTransferController;
use App\Http\Controllers\Apps\SupplierReturnController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// route purchase orders
Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase-orders-access')->name('purchase-orders.index');
Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->middleware('permission:purchase-orders-create')->name('purchase-orders.create');
Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase-orders-create')->name('purchase-orders.store');
Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase-orders-access')->name('purchase-orders.show');
Route::post('/purchase-orders/{purchaseOrder}/place', [PurchaseOrderController::class, 'placeOrder'])->middleware('permission:purchase-orders-update')->name('purchase-orders.place');
Route::post('/purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase-orders-update')->name('purchase-orders.cancel');

// route goods receivings
Route::get('/goods-receivings', [GoodsReceivingController::class, 'index'])->middleware('permission:goods-receivings-access')->name('goods-receivings.index');
Route::get('/goods-receivings/create', [GoodsReceivingController::class, 'create'])->middleware('permission:goods-receivings-create')->name('goods-receivings.create');
Route::post('/goods-receivings', [GoodsReceivingController::class, 'store'])->middleware('permission:goods-receivings-create')->name('goods-receivings.store');
Route::get('/goods-receivings/{goodsReceiving}', [GoodsReceivingController::class, 'show'])->middleware('permission:goods-receivings-access')->name('goods-receivings.show');

// route stock transfers
Route::get('/stock-transfers', [StockTransferController::class, 'index'])->middleware('permission:stock-transfers-access')->name('stock-transfers.index');
Route::get('/stock-transfers/create', [StockTransferController::class, 'create'])->middleware('permission:stock-transfers-create')->name('stock-transfers.create');
Route::post('/stock-transfers', [StockTransferController::class, 'store'])->middleware('permission:stock-transfers-create')->name('stock-transfers.store');
Route::get('/stock-transfers/{stockTransfer}', [StockTransferController::class, 'show'])->middleware('permission:stock-transfers-access')->name('stock-transfers.show');
Route::post('/stock-transfers/{stockTransfer}/send', [StockTransferController::class, 'send'])->middleware('permission:stock-transfers-send')->name('stock-transfers.send');
Route::post('/stock-transfers/{stockTransfer}/receive', [StockTransferController::class, 'receive'])->middleware('permission:stock-transfers-receive')->name('stock-transfers.receive');
Route::post('/stock-transfers/{stockTransfer}/cancel', [StockTransferController::class, 'cancel'])->middleware('permission:stock-transfers-cancel')->name('stock-transfers.cancel');

// route supplier returns
Route::get('/supplier-returns', [SupplierReturnController::class, 'index'])->middleware('permission:supplier-returns-access')->name('supplier-returns.index');
Route::get('/supplier-returns/create', [SupplierReturnController::class, 'create'])->middleware('permission:supplier-returns-create')->name('supplier-returns.create');
Route::post('/supplier-returns', [SupplierReturnController::class, 'store'])->middleware('permission:supplier-returns-create')->name('supplier-returns.store');
Route::get('/supplier-returns/{supplierReturn}', [SupplierReturnController::class, 'show'])->middleware('permission:supplier-returns-access')->name('supplier-returns.show');
Route::post('/supplier-returns/{supplierReturn}/complete', [SupplierReturnController::class, 'complete'])->middleware('permission:supplier-returns-update')->name('supplier-returns.complete');
Route::post('/supplier-returns/{supplierReturn}/cancel', [SupplierReturnController::class, 'cancel'])->middleware('permission:supplier-returns-update')->name('supplier-returns.cancel');
