<?php

use App\Http\Controllers\Apps\AuditLogController;
use App\Http\Controllers\Apps\CashierShiftController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('permission:audit-logs-access')->name('audit-logs.index');
Route::get('audit-logs/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:audit-logs-access')->name('audit-logs.show');
Route::get('cashier-shifts', [CashierShiftController::class, 'index'])->middleware('permission:cashier-shifts-access')->name('cashier-shifts.index');
Route::post('cashier-shifts', [CashierShiftController::class, 'store'])->middleware('permission:cashier-shifts-open')->name('cashier-shifts.store');
Route::get('cashier-shifts/{cashierShift}', [CashierShiftController::class, 'show'])->middleware('permission:cashier-shifts-access')->name('cashier-shifts.show');
Route::post('cashier-shifts/{cashierShift}/close', [CashierShiftController::class, 'close'])->middleware('permission:cashier-shifts-close')->name('cashier-shifts.close');
Route::post('cashier-shifts/{cashierShift}/cash-movements', [CashierShiftController::class, 'storeCashMovement'])->middleware('permission:cashier-shifts-access')->name('cashier-shifts.cash-movements.store');
Route::get('cashier-shifts/{cashierShift}/report/{type}', [CashierShiftController::class, 'printReport'])->middleware('permission:cashier-shifts-access')->name('cashier-shifts.report');
