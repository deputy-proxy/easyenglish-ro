<?php

use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

Route::get('/', [PublicSiteController::class, 'home'])->name('home');
Route::post('/language', [PublicSiteController::class, 'setLocale'])->middleware('throttle:20,1')->name('locale.set');
Route::get('/availability', [PublicSiteController::class, 'availability'])->middleware('throttle:60,1')->name('availability');
Route::get('/book', [PublicSiteController::class, 'bookingForm'])->name('booking.create');
Route::post('/book', [PublicSiteController::class, 'submitBooking'])->middleware('throttle:10,1')->name('booking.submit');
Route::post('/verification/start', [PublicSiteController::class, 'startContactVerification'])->middleware('throttle:10,1')->name('verification.start');
Route::post('/verification/phone', [PublicSiteController::class, 'confirmPhoneVerification'])->middleware('throttle:10,1')->name('verification.phone.confirm');
Route::get('/verification/email/{challenge}/{token}', [PublicSiteController::class, 'confirmEmailVerification'])->middleware('throttle:10,1')->name('verification.email.confirm');
Route::get('/booking/{reference}/manage', [PublicSiteController::class, 'manageBooking'])->middleware(['signed', 'throttle:60,1'])->name('booking.manage');
Route::post('/booking/{reference}/cancel', [PublicSiteController::class, 'cancelBooking'])->middleware(['signed', 'throttle:20,1'])->name('booking.cancel');
Route::get('/booking/{reference}/reschedule', [PublicSiteController::class, 'rescheduleForm'])->middleware(['signed', 'throttle:30,1'])->name('booking.reschedule.form');
Route::post('/booking/{reference}/reschedule', [PublicSiteController::class, 'rescheduleBooking'])->middleware(['signed', 'throttle:20,1'])->name('booking.reschedule');
Route::get('/booking/confirmed/{reference}', [PublicSiteController::class, 'confirmed'])->name('booking.confirmed');
Route::post('/stripe/webhook', StripeWebhookController::class)->withoutMiddleware([PreventRequestForgery::class])->middleware('throttle:120,1')->name('stripe.webhook');
Route::get('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:60,1')->name('whatsapp.webhook.verify');
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->withoutMiddleware([PreventRequestForgery::class])->middleware('throttle:120,1')->name('whatsapp.webhook.receive');
Route::get('/purchase/success', [PublicSiteController::class, 'purchaseSuccess'])->middleware('throttle:30,1')->name('purchase.success');
Route::get('/purchase/cancel/{bundle}', [PublicSiteController::class, 'purchaseCancel'])->middleware('throttle:30,1')->name('purchase.cancel');
Route::post('/purchase/{bundle}/checkout', [PublicSiteController::class, 'startCheckout'])->middleware('throttle:10,1')->name('purchase.checkout');
Route::get('/purchase/{bundle}', [PublicSiteController::class, 'purchase'])->name('purchase');
Route::get('/policy/{page}', [PublicSiteController::class, 'policy'])->name('policy');
Route::get('/privacy', [PublicSiteController::class, 'policy'])->defaults('page', 'privacy')->name('privacy');
Route::get('/terms', [PublicSiteController::class, 'policy'])->defaults('page', 'terms')->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
