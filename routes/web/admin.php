<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OutletContextController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\TourController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::post('/outlet', [OutletContextController::class, 'switch'])->name('outlet.switch');
Route::post('/tours/{tour}/complete', [TourController::class, 'complete'])->name('tours.complete');
Route::post('/tours/reset', [TourController::class, 'reset'])->name('tours.reset');
Route::get('/', [DashboardController::class, 'index'])->middleware(['permission:dashboard-access'])->name('dashboard');
Route::get('/permissions', [PermissionController::class, 'index'])->middleware('permission:permissions-access')->name('permissions.index');
// roles route
Route::resource('/roles', RoleController::class)
    ->except(['create', 'edit', 'show'])
    ->middlewareFor('index', 'permission:roles-access')
    ->middlewareFor('store', ['permission:roles-create', 'step_up'])
    ->middlewareFor('update', ['permission:roles-update', 'step_up'])
    ->middlewareFor('destroy', ['permission:roles-delete', 'step_up']);
// users route
Route::resource('/users', UserController::class)
    ->except('show')
    ->middlewareFor('index', 'permission:users-access')
    ->middlewareFor(['create', 'store'], 'permission:users-create')
    ->middlewareFor('store', ['permission:users-create', 'step_up'])
    ->middlewareFor(['edit', 'update'], 'permission:users-update')
    ->middlewareFor('update', ['permission:users-update', 'step_up'])
    ->middlewareFor('destroy', ['permission:users-delete', 'step_up']);
Route::post('/notifications/low-stock/read', [NotificationController::class, 'markLowStockRead'])->name('notifications.stock.read');
Route::post('/notifications/low-stock/read-all', [NotificationController::class, 'markAllLowStockRead'])->name('notifications.stock.readAll');
Route::get('/regions/regencies', [RegionController::class, 'regencies'])->name('regions.regencies');
Route::get('/regions/districts', [RegionController::class, 'districts'])->name('regions.districts');
Route::get('/regions/villages', [RegionController::class, 'villages'])->name('regions.villages');
