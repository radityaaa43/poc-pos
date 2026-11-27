<?php

use App\Http\Controllers\Apps\SalesReturnController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::get('/transactions/history/{transaction}/sales-return/create', [SalesReturnController::class, 'create'])->middleware('permission:sales-returns-create')->name('sales-returns.create');
Route::post('/transactions/history/{transaction}/sales-return', [SalesReturnController::class, 'store'])->middleware('permission:sales-returns-create')->name('sales-returns.store');
Route::get('/sales-returns', [SalesReturnController::class, 'index'])->middleware('permission:sales-returns-access')->name('sales-returns.index');
Route::get('/sales-returns/{salesReturn}', [SalesReturnController::class, 'show'])->middleware('permission:sales-returns-access')->name('sales-returns.show');
Route::patch('/sales-returns/{salesReturn}', [SalesReturnController::class, 'update'])->middleware('permission:sales-returns-create')->name('sales-returns.update');
Route::post('/sales-returns/{salesReturn}/complete', [SalesReturnController::class, 'complete'])->middleware('permission:sales-returns-complete')->name('sales-returns.complete');
