<?php

use App\Http\Controllers\Apps\DiscountApprovalController;
use App\Http\Controllers\Apps\TransactionController;
use App\Http\Controllers\Apps\TransactionRetryController;
use Illuminate\Support\Facades\Route;

// Required by routes/web.php inside its /dashboard group, so the prefix and `auth` apply.

// confirm payment for bank transfer
Route::patch('/transactions/{transaction}/confirm-payment', [TransactionController::class, 'confirmPayment'])->middleware(['permission:transactions-confirm-payment', 'step_up'])->name('transactions.confirm-payment');

// retry a failed or expired POS payment, same method or another one
Route::post('/transactions/{transaction}/retry-payment', TransactionRetryController::class)->middleware(['permission:transactions-access', 'active_shift'])->name('transactions.retry-payment');

// discount approval
Route::get('/discount-approvals', [DiscountApprovalController::class, 'pending'])->middleware('permission:discounts-approve')->name('discount-approvals.pending');
Route::post('/discount-approvals/{transaction}/approve', [DiscountApprovalController::class, 'approve'])->middleware('permission:discounts-approve')->name('discount-approvals.approve');
Route::post('/discount-approvals/{transaction}/deny', [DiscountApprovalController::class, 'deny'])->middleware('permission:discounts-approve')->name('discount-approvals.deny');
