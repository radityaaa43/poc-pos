<?php

use App\Http\Controllers\Apps\KitchenController;
use App\Http\Controllers\Apps\KitchenPrintDeviceController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::get('/kitchen', [KitchenController::class, 'index'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.index');
Route::post('/kitchen/items/{kitchenItem}/start', [KitchenController::class, 'start'])->middleware('permission:dine-orders-prepare')->name('kitchen.items.start');
Route::post('/kitchen/items/{kitchenItem}/ready', [KitchenController::class, 'ready'])->middleware('permission:dine-orders-prepare')->name('kitchen.items.ready');
Route::post('/kitchen/orders/{transaction}/all-ready', [KitchenController::class, 'allReady'])->middleware('permission:dine-orders-prepare')->name('kitchen.orders.all-ready');
Route::post('/kitchen/print-devices', [KitchenPrintDeviceController::class, 'store'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.print-devices.store');
Route::get('/kitchen/print-devices/{device}/tickets', [KitchenPrintDeviceController::class, 'tickets'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.print-devices.tickets');
Route::put('/kitchen/print-devices/{device}/stations', [KitchenPrintDeviceController::class, 'stations'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.print-devices.stations');
Route::post('/kitchen/print-devices/{device}/prints', [KitchenPrintDeviceController::class, 'prints'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.print-devices.prints');
Route::post('/kitchen/print-devices/{device}/cancellation-prints', [KitchenPrintDeviceController::class, 'cancellationPrints'])->middleware('permission:dine-orders-prepare|dine-orders-handover')->name('kitchen.print-devices.cancellation-prints');
Route::post('/kitchen/items/{kitchenItem}/hand-over', [KitchenController::class, 'handOver'])->middleware('permission:dine-orders-handover')->name('kitchen.items.hand-over');
Route::post('/kitchen/orders/{transaction}/hand-over-all', [KitchenController::class, 'handOverAll'])->middleware('permission:dine-orders-handover')->name('kitchen.orders.hand-over-all');
