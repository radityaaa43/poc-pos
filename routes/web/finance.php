<?php

use App\Http\Controllers\Apps\CrmCampaignController;
use App\Http\Controllers\Apps\PayableController;
use App\Http\Controllers\Apps\ReceivableController;
use App\Http\Controllers\Apps\SupplierController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// receivables (nota barang)
Route::get('/receivables', [ReceivableController::class, 'index'])->middleware('permission:receivables-access')->name('receivables.index');
Route::get('/receivables/aging', [ReceivableController::class, 'aging'])->middleware('permission:receivables-access')->name('receivables.aging');
Route::get('/receivables/customer-statement', [ReceivableController::class, 'customerStatement'])->middleware('permission:receivables-access')->name('receivables.customer-statement');
Route::get('/receivables/{receivable}', [ReceivableController::class, 'show'])->middleware('permission:receivables-access')->name('receivables.show');
Route::patch('/receivables/{receivable}/collection-notes', [ReceivableController::class, 'updateCollectionNotes'])->middleware('permission:receivables-access')->name('receivables.collection-notes');
Route::post('/receivables/{receivable}/pay', [ReceivableController::class, 'pay'])->middleware('permission:receivables-pay')->name('receivables.pay');
Route::post('/receivables/{receivable}/share-campaign', [CrmCampaignController::class, 'shareReceivable'])->middleware('permission:crm-campaigns-create')->name('receivables.share-campaign');
// suppliers & payables
Route::get('/suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers-access')->name('suppliers.index');
Route::post('/suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers-access')->name('suppliers.store');
Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers-access')->name('suppliers.update');
Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers-access')->name('suppliers.destroy');
Route::get('/payables', [PayableController::class, 'index'])->middleware('permission:payables-access')->name('payables.index');
Route::post('/payables', [PayableController::class, 'store'])->middleware('permission:payables-access')->name('payables.store');
Route::get('/payables/supplier-statement', [PayableController::class, 'supplierStatement'])->middleware('permission:payables-access')->name('payables.supplier-statement');
Route::get('/payables/{payable}', [PayableController::class, 'show'])->middleware('permission:payables-access')->name('payables.show');
Route::post('/payables/{payable}/pay', [PayableController::class, 'pay'])->middleware('permission:payables-pay')->name('payables.pay');

// pdf documents
Route::get('/documents/transactions/{invoice}/pdf/invoice', [DocumentController::class, 'invoice'])->middleware('permission:transactions-access')->name('pdf.transactions.invoice');
Route::get('/documents/transactions/{invoice}/pdf/receipt/{size?}', [DocumentController::class, 'receipt'])->middleware('permission:transactions-access')->name('pdf.transactions.receipt');
Route::get('/documents/transactions/{invoice}/print/thermal', [DocumentController::class, 'thermalPrint'])->middleware('permission:transactions-access')->name('pdf.transactions.thermal');
Route::get('/documents/transactions/{invoice}/pdf/shipping', [DocumentController::class, 'shipping'])->middleware('permission:transactions-access')->name('pdf.transactions.shipping');
Route::get('/documents/receivables/{receivable}/pdf', [DocumentController::class, 'receivable'])->middleware('permission:receivables-access')->name('pdf.receivables.show');
Route::get('/documents/payables/{payable}/pdf', [DocumentController::class, 'payable'])->middleware('permission:payables-access')->name('pdf.payables.show');
