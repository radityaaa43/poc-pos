<?php

use App\Http\Controllers\Apps\CategoryController;
use App\Http\Controllers\Apps\ImportExportController;
use App\Http\Controllers\Apps\PricingRuleController;
use App\Http\Controllers\Apps\ProductController;
use App\Http\Controllers\Apps\RawMaterialController;
use App\Http\Controllers\Apps\StockMutationController;
use App\Http\Controllers\Apps\StockOpnameController;
use App\Http\Controllers\Apps\StockWasteController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::resource('categories', CategoryController::class)
    ->middlewareFor(['index', 'show'], 'permission:categories-access')
    ->middlewareFor(['create', 'store'], 'permission:categories-create')
    ->middlewareFor(['edit', 'update'], 'permission:categories-edit')
    ->middlewareFor('destroy', 'permission:categories-delete');
Route::resource('products', ProductController::class)
    ->middlewareFor(['index', 'show'], 'permission:products-access')
    ->middlewareFor(['create', 'store'], 'permission:products-create')
    ->middlewareFor(['edit', 'update'], 'permission:products-edit')
    ->middlewareFor('destroy', 'permission:products-delete');
Route::resource('raw-materials', RawMaterialController::class)
    ->except('show')
    ->parameters(['raw-materials' => 'product'])
    ->middlewareFor('index', 'permission:products-access')
    ->middlewareFor(['create', 'store'], 'permission:products-create')
    ->middlewareFor(['edit', 'update'], 'permission:products-edit')
    ->middlewareFor('destroy', 'permission:products-delete');

// import/export
Route::get('/export/products', [ImportExportController::class, 'exportProducts'])->middleware('permission:products-export')->name('export.products');
Route::get('/export/customers', [ImportExportController::class, 'exportCustomers'])->middleware('permission:customers-export')->name('export.customers');
Route::get('/export/transactions', [ImportExportController::class, 'exportTransactions'])->middleware('permission:transactions-access')->name('export.transactions');
Route::post('/import/products', [ImportExportController::class, 'importProducts'])->middleware('permission:products-import')->name('import.products');
Route::post('/import/customers', [ImportExportController::class, 'importCustomers'])->middleware('permission:customers-import')->name('import.customers');
Route::get('/import/template/{type}', [ImportExportController::class, 'downloadTemplate'])->name('import.template');
Route::resource('pricing-rules', PricingRuleController::class)
    ->except(['show'])
    ->middlewareFor('index', 'permission:pricing-rules-access')
    ->middlewareFor(['create', 'store'], 'permission:pricing-rules-create')
    ->middlewareFor(['edit', 'update'], 'permission:pricing-rules-update')
    ->middlewareFor('destroy', 'permission:pricing-rules-delete');
Route::post('pricing-rules/preview', [PricingRuleController::class, 'preview'])
    ->middleware('permission:pricing-rules-access')
    ->name('pricing-rules.preview');
Route::get('stock-opnames', [StockOpnameController::class, 'index'])->middleware('permission:stock-opnames-access')->name('stock-opnames.index');
Route::get('stock-opnames/create', [StockOpnameController::class, 'create'])->middleware('permission:stock-opnames-create')->name('stock-opnames.create');
Route::post('stock-opnames', [StockOpnameController::class, 'store'])->middleware('permission:stock-opnames-create')->name('stock-opnames.store');
Route::get('stock-opnames/{stockOpname}', [StockOpnameController::class, 'show'])->middleware('permission:stock-opnames-access')->name('stock-opnames.show');
Route::patch('stock-opnames/{stockOpname}', [StockOpnameController::class, 'update'])->middleware('permission:stock-opnames-create')->name('stock-opnames.update');
Route::post('stock-opnames/{stockOpname}/items', [StockOpnameController::class, 'storeItem'])->middleware('permission:stock-opnames-create')->name('stock-opnames.items.store');
Route::patch('stock-opnames/{stockOpname}/items/{item}', [StockOpnameController::class, 'updateItem'])->middleware('permission:stock-opnames-create')->name('stock-opnames.items.update');
Route::post('stock-opnames/{stockOpname}/finalize', [StockOpnameController::class, 'finalize'])->middleware('permission:stock-opnames-finalize')->name('stock-opnames.finalize');
Route::get('stock-mutations', [StockMutationController::class, 'index'])->middleware('permission:stock-mutations-access')->name('stock-mutations.index');
Route::get('stock-waste', [StockWasteController::class, 'index'])->middleware('permission:stock-waste-create|stock-waste-approve')->name('stock-waste.index');
Route::get('stock-waste/create', [StockWasteController::class, 'create'])->middleware('permission:stock-waste-create')->name('stock-waste.create');
Route::post('stock-waste', [StockWasteController::class, 'store'])->middleware('permission:stock-waste-create')->name('stock-waste.store');
