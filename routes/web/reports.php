<?php

use App\Http\Controllers\Apps\AgingController;
use App\Http\Controllers\Reports\AdvancedSalesInsightsController;
use App\Http\Controllers\Reports\KitchenSpeedReportController;
use App\Http\Controllers\Reports\ProfitReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Http\Controllers\Reports\WasteReportController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// reports
Route::get('/reports/sales', [SalesReportController::class, 'index'])->middleware('permission:reports-access')->name('reports.sales.index');
Route::get('/reports/profits', [ProfitReportController::class, 'index'])->middleware('permission:profits-access')->name('reports.profits.index');
Route::get('/reports/insights', [AdvancedSalesInsightsController::class, 'index'])->middleware('permission:reports-access')->name('reports.insights.index');
Route::get('/reports/kitchen-speed', [KitchenSpeedReportController::class, 'index'])->middleware('permission:reports-access')->name('reports.kitchen-speed.index');
Route::get('/reports/waste', [WasteReportController::class, 'index'])->middleware('permission:profits-access')->name('reports.waste.index');

// aging & reminders
Route::get('/aging', [AgingController::class, 'index'])->middleware('permission:receivables-access')->name('aging.index');
