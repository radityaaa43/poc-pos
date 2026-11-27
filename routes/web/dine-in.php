<?php

use App\Http\Controllers\Apps\DineAreaController;
use App\Http\Controllers\Apps\DineOrderController;
use App\Http\Controllers\Apps\DineTableController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// dine-in areas
Route::resource('/dine-areas', DineAreaController::class)
    ->middleware('permission:dine-tables-access')
    ->except(['create', 'edit', 'show']);

// dine-in tables
Route::get('/dine-tables', [DineTableController::class, 'index'])->middleware('permission:dine-tables-access')->name('dine-tables.index');
Route::post('/dine-tables', [DineTableController::class, 'store'])->middleware('permission:dine-tables-create')->name('dine-tables.store');
Route::patch('/dine-tables/{dineTable}', [DineTableController::class, 'update'])->middleware('permission:dine-tables-update')->name('dine-tables.update');
Route::delete('/dine-tables/{dineTable}', [DineTableController::class, 'destroy'])->middleware('permission:dine-tables-delete')->name('dine-tables.destroy');
Route::get('/dine-tables/{dineTable}/qr', [DineTableController::class, 'qr'])->middleware('permission:dine-tables-access')->name('dine-tables.qr');

// dine-in orders
Route::get('/dine-orders', [DineOrderController::class, 'index'])->middleware('permission:dine-orders-access')->name('dine-orders.index');
Route::post('/dine-orders/{dineOrder}/accept', [DineOrderController::class, 'accept'])->middleware('permission:dine-orders-process')->name('dine-orders.accept');
Route::post('/dine-orders/{dineOrder}/prepare', [DineOrderController::class, 'prepare'])->middleware('permission:dine-orders-prepare')->name('dine-orders.prepare');
Route::post('/dine-orders/{dineOrder}/ready', [DineOrderController::class, 'ready'])->middleware('permission:dine-orders-prepare')->name('dine-orders.ready');
Route::post('/dine-orders/{dineOrder}/serve', [DineOrderController::class, 'serve'])->middleware('permission:dine-orders-handover')->name('dine-orders.serve');
Route::post('/dine-orders/{dineOrder}/pay', [DineOrderController::class, 'pay'])->middleware(['permission:dine-orders-process', 'active_shift'])->name('dine-orders.pay');
Route::post('/dine-orders/{dineOrder}/reject', [DineOrderController::class, 'reject'])->middleware('permission:dine-orders-process')->name('dine-orders.reject');
Route::get('/scratch-b', fn () => 'ok')->name('scratch.b');
