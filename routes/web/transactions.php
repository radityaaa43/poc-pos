<?php

use App\Http\Controllers\Api\PosApiController;
use App\Http\Controllers\Apps\CrmCampaignController;
use App\Http\Controllers\Apps\PosTableListController;
use App\Http\Controllers\Apps\PosTableOrderController;
use App\Http\Controllers\Apps\TransactionController;
use App\Http\Controllers\Apps\TransactionSettleController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// route transaction
Route::get('/transactions', [TransactionController::class, 'index'])->middleware('permission:transactions-access')->name('transactions.index');

// route transaction searchProduct
Route::post('/transactions/searchProduct', [TransactionController::class, 'searchProduct'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.searchProduct');

// route transaction addToCart
Route::post('/transactions/addToCart', [TransactionController::class, 'addToCart'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.addToCart');

// route transaction destroyCart
Route::delete('/transactions/{cart_id}/destroyCart', [TransactionController::class, 'destroyCart'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.destroyCart');

// route transaction updateCart
Route::patch('/transactions/{cart_id}/updateCart', [TransactionController::class, 'updateCart'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.updateCart');
Route::post('/transactions/pricing-preview', [TransactionController::class, 'previewPricing'])->middleware(['permission:transactions-access', 'active_shift', 'keep_flash'])->name('transactions.pricing-preview');

// route hold transaction
Route::post('/transactions/hold', [TransactionController::class, 'holdCart'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.hold');
Route::post('/transactions/{holdId}/resume', [TransactionController::class, 'resumeCart'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.resume');
Route::delete('/transactions/{holdId}/clearHold', [TransactionController::class, 'clearHold'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.clearHold');
Route::get('/transactions/held', [TransactionController::class, 'getHeldCarts'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.held');

// the POS "Belum Lunas" list, polled by the browser
Route::get('/transactions/open-bills', [TransactionController::class, 'openBills'])->middleware(['permission:transactions-access', 'keep_flash'])->name('transactions.open-bills');

// route transaction store
Route::post('/transactions/store', [TransactionController::class, 'store'])->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.store');
Route::post('/transactions/{transaction}/settle', TransactionSettleController::class)->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.settle');
// offline queue flush from the browser (session auth); the shift is checked per sale in syncOne()
Route::post('/transactions/sync', [PosApiController::class, 'syncTransactions'])->middleware(['permission:transactions-access', 'keep_flash'])->name('transactions.sync');
Route::get('/transactions/{invoice}/status', [TransactionController::class, 'status'])->middleware(['permission:transactions-access', 'keep_flash'])->name('transactions.status');
Route::get('/transactions/{invoice}/qr', [TransactionController::class, 'qrisImage'])->middleware('permission:transactions-access')->name('transactions.qr');
Route::get('/transactions/{invoice}/print', [TransactionController::class, 'print'])->middleware('permission:transactions-access')->name('transactions.print');
Route::get('/transactions/history', [TransactionController::class, 'history'])->middleware('permission:transactions-access')->name('transactions.history');
Route::post('/transactions/{transaction}/share-campaign', [CrmCampaignController::class, 'shareTransaction'])->middleware('permission:crm-campaigns-create')->name('transactions.share-campaign');

// table orders taken on the POS (#426); the list is asked for on page load, before any shift is open
Route::get('/transactions/tables', PosTableListController::class)->middleware(['permission:transactions-access', 'keep_flash'])->name('transactions.tables');
// No active_shift: it refuses with a redirect that Inertia reads as success. The service requires the shift under lock.
Route::post('/transactions/table-orders', PosTableOrderController::class)->middleware(['permission:transactions-access', 'keep_flash'])->name('transactions.table-orders.store');
