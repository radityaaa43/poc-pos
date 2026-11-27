<?php

use App\Http\Controllers\DineMenuController;
use App\Http\Controllers\DineOrderController;
use App\Http\Controllers\DineOrderPaymentController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\LanguageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPortalController;
use App\Http\Controllers\SetupController;
use App\Models\Setting;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (! Setting::getBool('app_setup_completed', false)) {
        return redirect()->route('setup.index');
    }

    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => config('security.auth.public_registration'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// First-install setup wizard (locked out once app_setup_completed = true)
Route::middleware('setup.notinstalled')->group(function () {
    Route::get('/setup', [SetupController::class, 'index'])->name('setup.index');
    Route::post('/setup', [SetupController::class, 'store'])->middleware('throttle:10,1')->name('setup.store');
});

// Public marketing pages (open source)
Route::get('/fitur', fn () => Inertia::render('Public/Features'))->name('features.index');
Route::get('/dokumentasi', fn () => Inertia::render('Public/Documentation'))->name('documentation.index');
Route::get('/roadmap', fn () => Inertia::render('Public/Roadmap'))->name('roadmap.index');
Route::get('/kontribusi', fn () => Inertia::render('Public/Contributing'))->name('contributing.index');

Route::get('/dashboard/access', function () {
    return Inertia::render('Dashboard/Access');
})->middleware(['auth'])->name('dashboard.access');

// Public share routes (no login, but require the transaction access token)
Route::get('/share/transactions/{invoice}', [DocumentController::class, 'publicInvoice'])
    ->middleware('throttle:10,1')
    ->name('transactions.public');

// Customer portal routes (no login, token-based)
Route::get('/portal/transactions/{invoice}', [PublicPortalController::class, 'showTransaction'])
    ->middleware('throttle:10,1')
    ->name('portal.transaction');
Route::post('/portal/receivables/{receivable}/pay', [PublicPortalController::class, 'payReceivable'])
    ->middleware('throttle:5,1')
    ->name('portal.receivable.pay');

// Language switch
Route::post('/language/switch', [LanguageController::class, 'switch'])->name('language.switch');

Route::group(['prefix' => 'dashboard', 'middleware' => ['auth']], function () {
    // One file per module: add a route to its module's file. Registration order matters
    // (e.g. /transactions/open-bills before /transactions/{invoice}/...), and it is `require`,
    // not `require_once`, because tests boot the app many times in one process.
    require __DIR__.'/web/admin.php';
    require __DIR__.'/web/inventory.php';
    require __DIR__.'/web/audit-and-shifts.php';
    require __DIR__.'/web/customers.php';
    require __DIR__.'/web/transactions.php';
    require __DIR__.'/web/sales-returns.php';
    require __DIR__.'/web/purchasing.php';
    require __DIR__.'/web/finance.php';
    require __DIR__.'/web/settings.php';
    require __DIR__.'/web/transaction-actions.php';
    require __DIR__.'/web/reports.php';
    require __DIR__.'/web/dine-in.php';
    require __DIR__.'/web/kitchen.php';

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// dine-in public routes
Route::get('/dine/{token}', [DineMenuController::class, 'show'])->name('dine.menu');
Route::post('/dine/{token}/order', [DineOrderController::class, 'store'])->name('dine-order.store');
Route::get('/dine-order/{accessToken}', [DineOrderController::class, 'status'])->name('dine-order.status');
Route::get('/dine-order/{accessToken}/check', [DineOrderController::class, 'statusCheck'])->middleware('throttle:30,1')->name('dine-order.status-check');
Route::post('/dine-order/{accessToken}/pay', DineOrderPaymentController::class)->middleware('throttle:dine-order-pay')->name('dine-order.pay');

require __DIR__.'/auth.php';
