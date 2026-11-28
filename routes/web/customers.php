<?php

use App\Http\Controllers\Apps\CrmCampaignController;
use App\Http\Controllers\Apps\CrmReminderController;
use App\Http\Controllers\Apps\CustomerController;
use App\Http\Controllers\Apps\CustomerSegmentController;
use App\Http\Controllers\Apps\CustomerVoucherController;
use App\Http\Controllers\Apps\MemberController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

Route::resource('customers', CustomerController::class)
    ->middlewareFor(['index', 'show'], 'permission:customers-access')
    ->middlewareFor(['create', 'store'], 'permission:customers-create')
    ->middlewareFor(['edit', 'update'], 'permission:customers-edit')
    ->middlewareFor('destroy', 'permission:customers-delete');
Route::resource('members', MemberController::class)
    ->parameters(['members' => 'member'])
    ->except(['destroy'])
    ->middlewareFor(['index', 'show'], 'permission:customers-access')
    ->middlewareFor(['create', 'store'], 'permission:customers-create')
    ->middlewareFor(['edit', 'update'], 'permission:customers-edit');
Route::resource('customer-vouchers', CustomerVoucherController::class)
    ->except(['show'])
    ->middlewareFor('index', 'permission:customer-vouchers-access')
    ->middlewareFor(['create', 'store'], 'permission:customer-vouchers-create')
    ->middlewareFor(['edit', 'update'], 'permission:customer-vouchers-update')
    ->middlewareFor('destroy', 'permission:customer-vouchers-delete');
Route::resource('customer-segments', CustomerSegmentController::class)
    ->middlewareFor(['index', 'show'], 'permission:customer-segments-access')
    ->middlewareFor(['create', 'store'], 'permission:customer-segments-create')
    ->middlewareFor(['edit', 'update'], 'permission:customer-segments-update')
    ->middlewareFor('destroy', 'permission:customer-segments-delete');
Route::post('customer-segments/{customerSegment}/members', [CustomerSegmentController::class, 'storeMember'])
    ->middleware('permission:customer-segments-update')
    ->name('customer-segments.members.store');
Route::delete('customer-segments/{customerSegment}/members/{customer}', [CustomerSegmentController::class, 'destroyMember'])
    ->middleware('permission:customer-segments-update')
    ->name('customer-segments.members.destroy');
Route::resource('crm-campaigns', CrmCampaignController::class)
    ->middlewareFor(['index', 'show'], 'permission:crm-campaigns-access')
    ->middlewareFor(['create', 'store'], 'permission:crm-campaigns-create')
    ->middlewareFor(['edit', 'update'], 'permission:crm-campaigns-update')
    ->middlewareFor('destroy', 'permission:crm-campaigns-delete');
Route::post('crm-campaigns/{crmCampaign}/process', [CrmCampaignController::class, 'process'])
    ->middleware('permission:crm-campaigns-update')
    ->name('crm-campaigns.process');
Route::post('crm-campaigns/{crmCampaign}/cancel', [CrmCampaignController::class, 'cancel'])
    ->middleware('permission:crm-campaigns-update')
    ->name('crm-campaigns.cancel');
Route::post('crm-campaign-logs/{log}/mark-sent', [CrmCampaignController::class, 'markLogSent'])
    ->middleware('permission:crm-campaigns-update')
    ->name('crm-campaign-logs.mark-sent');
Route::post('crm-campaign-logs/{log}/skip', [CrmCampaignController::class, 'markLogSkipped'])
    ->middleware('permission:crm-campaigns-update')
    ->name('crm-campaign-logs.skip');
Route::get('crm-reminders', [CrmReminderController::class, 'index'])
    ->middleware('permission:crm-reminders-access')
    ->name('crm-reminders.index');

// route customer history
Route::get('/customers/{customer}/history', [CustomerController::class, 'getHistory'])->middleware('permission:transactions-access')->name('customers.history');
Route::put('/customers/{customer}/segments', [CustomerController::class, 'syncSegments'])->middleware('permission:customers-edit')->name('customers.segments.sync');
Route::match(['post', 'put'], '/customers/{customer}/upgrade-member', [CustomerController::class, 'upgradeToMember'])
    ->middleware('permission:customers-upgrade-member|customers-edit')
    ->name('customers.upgrade-member');
Route::patch('/customers/{customer}/credit', [CustomerController::class, 'updateCredit'])->middleware('permission:customers-credit-approve')->name('customers.credit');
Route::patch('/customers/{customer}/credit-limit', [CustomerController::class, 'updateCreditLimit'])->middleware('permission:customers-credit-approve')->name('customers.credit-limit');
Route::get('/customers/{customer}/credit-summary', [CustomerController::class, 'creditSummary'])->middleware('permission:transactions-access')->name('customers.credit-summary');

// route customer store via AJAX (no redirect)
Route::post('/customers/store-ajax', [CustomerController::class, 'storeAjax'])->middleware('permission:customers-create')->name('customers.storeAjax');
