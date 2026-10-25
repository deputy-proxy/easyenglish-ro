<?php

use App\Http\Controllers\PublicSiteController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::post('/language', [PublicSiteController::class, 'setLocale'])->middleware('throttle:20,1')->name('locale.set');
Route::get('/availability', [PublicSiteController::class, 'availability'])->middleware('throttle:60,1')->name('availability');
Route::get('/book', [PublicSiteController::class, 'bookingForm'])->name('booking.create');
Route::post('/book', [PublicSiteController::class, 'submitBooking'])->middleware('throttle:10,1')->name('booking.submit');
Route::get('/booking/confirmed/{reference}', [PublicSiteController::class, 'confirmed'])->name('booking.confirmed');
Route::get('/purchase/{bundle}', [PublicSiteController::class, 'purchase'])->name('purchase');
Route::get('/policy/{page}', [PublicSiteController::class, 'policy'])->name('policy');
Route::get('/privacy', [PublicSiteController::class, 'policy'])->defaults('page', 'privacy')->name('privacy');
Route::get('/terms', [PublicSiteController::class, 'policy'])->defaults('page', 'terms')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
