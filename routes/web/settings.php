<?php

use App\Http\Controllers\Apps\BankAccountController;
use App\Http\Controllers\Apps\KitchenStationController;
use App\Http\Controllers\Apps\ModifierGroupController;
use App\Http\Controllers\Apps\OutletController;
use App\Http\Controllers\Apps\PaymentSettingController;
use App\Http\Controllers\Apps\PriceListController;
use App\Http\Controllers\Apps\SettingController;
use App\Http\Controllers\Apps\UnitController;
use App\Http\Controllers\Apps\WarehouseController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::get('/settings/payments', [PaymentSettingController::class, 'edit'])->middleware('permission:payment-settings-access')->name('settings.payments.edit');
Route::put('/settings/payments', [PaymentSettingController::class, 'update'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.payments.update');

// settings target penjualan
Route::get('/settings/target', [SettingController::class, 'target'])->middleware('permission:dashboard-access')->name('settings.target');
Route::post('/settings/target', [SettingController::class, 'updateTarget'])->middleware('permission:dashboard-access')->name('settings.target.update');
Route::get('/settings/store', [SettingController::class, 'storeProfile'])->middleware('permission:dashboard-access')->name('settings.store');
Route::post('/settings/store', [SettingController::class, 'updateStoreProfile'])->middleware('permission:dashboard-access')->name('settings.store.update');
Route::get('/settings/printer', [SettingController::class, 'printer'])->middleware('permission:dashboard-access')->name('settings.printer');
Route::post('/settings/printer', [SettingController::class, 'updatePrinter'])->middleware('permission:dashboard-access')->name('settings.printer.update');
Route::get('/settings/loyalty', [SettingController::class, 'loyalty'])->middleware('permission:dashboard-access')->name('settings.loyalty');
Route::post('/settings/loyalty', [SettingController::class, 'updateLoyalty'])->middleware('permission:dashboard-access')->name('settings.loyalty.update');

// settings whatsapp
Route::get('/settings/whatsapp', [SettingController::class, 'whatsapp'])->middleware('permission:whatsapp-settings-access')->name('settings.whatsapp');
Route::post('/settings/whatsapp', [SettingController::class, 'updateWhatsapp'])->middleware('permission:whatsapp-settings-update')->name('settings.whatsapp.update');
Route::post('/settings/whatsapp/test', [SettingController::class, 'testWhatsapp'])->middleware('permission:whatsapp-settings-update')->name('settings.whatsapp.test');
Route::post('/settings/whatsapp/start', [SettingController::class, 'startWhatsapp'])->middleware('permission:whatsapp-settings-update')->name('settings.whatsapp.start');
Route::get('/settings/whatsapp/status', [SettingController::class, 'whatsappStatus'])->middleware('permission:whatsapp-settings-access')->name('settings.whatsapp.status');
Route::post('/settings/whatsapp/disconnect', [SettingController::class, 'disconnectWhatsapp'])->middleware('permission:whatsapp-settings-update')->name('settings.whatsapp.disconnect');

// settings bank accounts
Route::get('/settings/bank-accounts', [BankAccountController::class, 'index'])->middleware('permission:payment-settings-access')->name('settings.bank-accounts.index');
Route::get('/settings/bank-accounts/create', [BankAccountController::class, 'create'])->middleware('permission:payment-settings-update')->name('settings.bank-accounts.create');
Route::post('/settings/bank-accounts', [BankAccountController::class, 'store'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.bank-accounts.store');
Route::get('/settings/bank-accounts/{bankAccount}/edit', [BankAccountController::class, 'edit'])->middleware('permission:payment-settings-update')->name('settings.bank-accounts.edit');
Route::put('/settings/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.bank-accounts.update');
Route::delete('/settings/bank-accounts/{bankAccount}', [BankAccountController::class, 'destroy'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.bank-accounts.destroy');
Route::patch('/settings/bank-accounts/{bankAccount}/toggle', [BankAccountController::class, 'toggleActive'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.bank-accounts.toggle');
Route::post('/settings/bank-accounts/order', [BankAccountController::class, 'updateOrder'])->middleware(['permission:payment-settings-update', 'step_up'])->name('settings.bank-accounts.order');

// settings price lists
Route::resource('/settings/price-lists', PriceListController::class)
    ->except(['create', 'edit'])
    ->middlewareFor(['index', 'show'], 'permission:price-lists-access')
    ->middlewareFor('store', ['permission:price-lists-create', 'step_up'])
    ->middlewareFor('update', ['permission:price-lists-update', 'step_up'])
    ->middlewareFor('destroy', ['permission:price-lists-delete', 'step_up']);
Route::post('/settings/price-lists/{priceList}/items', [PriceListController::class, 'updateItem'])->middleware(['permission:price-lists-update', 'step_up'])->name('price-lists.items.update');
Route::delete('/settings/price-lists/{priceList}/items/{productId}', [PriceListController::class, 'destroyItem'])->middleware(['permission:price-lists-update', 'step_up'])->name('price-lists.items.destroy');

// settings warehouses
Route::resource('/settings/warehouses', WarehouseController::class)
    ->except(['create', 'edit', 'show'])
    ->names('settings.warehouses')
    ->middlewareFor('index', 'permission:warehouses-access')
    ->middlewareFor('store', 'permission:warehouses-create')
    ->middlewareFor('update', 'permission:warehouses-update')
    ->middlewareFor('destroy', 'permission:warehouses-delete');

Route::get('/settings/outlets', [OutletController::class, 'index'])
    ->middleware('permission:outlets-access')
    ->name('settings.outlets.index');
Route::post('/settings/outlets', [OutletController::class, 'store'])
    ->middleware(['permission:outlets-create', 'step_up'])
    ->name('settings.outlets.store');
Route::put('/settings/outlets/{outlet}', [OutletController::class, 'update'])
    ->middleware(['permission:outlets-update', 'step_up'])
    ->name('settings.outlets.update');
Route::delete('/settings/outlets/{outlet}', [OutletController::class, 'destroy'])
    ->middleware(['permission:outlets-delete', 'step_up'])
    ->name('settings.outlets.destroy');

// settings units
Route::resource('/settings/units', UnitController::class)
    ->except(['create', 'edit', 'show'])
    ->names('settings.units')
    ->middlewareFor('index', 'permission:units-access')
    ->middlewareFor('store', 'permission:units-create')
    ->middlewareFor('update', 'permission:units-update')
    ->middlewareFor('destroy', 'permission:units-delete');

// settings kitchen stations (shop-wide, REQ-006)
Route::resource('/settings/kitchen-stations', KitchenStationController::class)
    ->except(['create', 'edit', 'show'])
    ->names('settings.kitchen-stations')
    ->middleware('permission:products-edit');

// settings modifier groups (shop-wide, REQ-008)
Route::resource('/settings/modifiers', ModifierGroupController::class)
    ->except(['create', 'edit', 'show'])
    ->parameters(['modifiers' => 'modifierGroup'])
    ->names('settings.modifiers')
    ->middleware('permission:modifiers-manage');
