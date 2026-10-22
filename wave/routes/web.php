<?php

use App\Http\Middleware\DenyOnAppliance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Wave\Actions\Reset;
use Wave\Page;

Route::impersonate();

// IAMS — OAuth social login via Socialite
Route::get('auth/{provider}', '\Wave\Http\Controllers\sosIAMSController@login')->name('wave.social.login');
Route::get('auth/{provider}/callback', '\Wave\Http\Controllers\sosIAMSController@callback')->name('wave.social.callback');

// Auth routes (Wave 2.x style — classic controller-based)
Route::get('logout', '\Wave\Http\Controllers\Auth\LoginController@logout')->name('wave.logout');
Route::get('user/verify/{verification_code}', '\Wave\Http\Controllers\Auth\RegisterController@verify')->name('verify');
Route::post('register/complete', '\Wave\Http\Controllers\Auth\RegisterController@complete')->name('wave.register-complete');

Route::get('password/email', '\Wave\Http\Controllers\Auth\ResetPasswordController@showChangeRequestForm')->name('password.email');
Route::post('password/email', '\Wave\Http\Controllers\Auth\ResetPasswordController@sendChangeRequestEmail');
Route::get('password/reset/{token}', '\Wave\Http\Controllers\Auth\ResetPasswordController@showResetForm')->name('password.reset');
Route::post('password/reset', '\Wave\Http\Controllers\Auth\ResetPasswordController@resetPassword')->name('password.request');

Route::get('contactus', '\App\Http\Controllers\MailController@index')->name('wave.contactus');
Route::post('contactus', '\App\Http\Controllers\MailController@contactus');

Route::group(['middleware' => 'auth'], function () {
    Route::redirect('settings', 'settings/profile')->name('settings');

    if (config('wave.billing_provider') == 'paddle') {
        Route::get('settings/invoices/{invoice}', '\Wave\Http\Controllers\SubscriptionController@invoice')
            ->middleware(DenyOnAppliance::class)
            ->name('wave.paddle.invoice');
    }

    Route::post('notification/read/{id}', '\Wave\Http\Controllers\NotificationController@delete')->name('wave.notification.read');
    Route::post('changelog/read', '\Wave\Http\Controllers\ChangelogController@read')->name('changelog.read');

    /********** Checkout/Billing Routes (SaaS only — DenyOnAppliance per-request) ***********/
    Route::middleware(DenyOnAppliance::class)->group(function () {
        Route::post('cancel', '\Wave\Http\Controllers\SubscriptionController@cancel')->name('wave.cancel');
        Route::post('expandDisk', '\Wave\Http\Controllers\SubscriptionController@expandDisk')->name('wave.expandDisk');
        Route::post('cancelDisk', '\Wave\Http\Controllers\SubscriptionController@cancelDisk')->name('wave.cancelDisk');
        Route::post('scheduleCancelDisk', '\Wave\Http\Controllers\SubscriptionController@scheduleCancelDisk')->name('wave.scheduleCancelDisk');
        Route::post('addTokens', '\Wave\Http\Controllers\SubscriptionController@addTokens')->name('wave.addTokens');
        Route::post('addSeats', '\Wave\Http\Controllers\SubscriptionController@addSeats')->name('wave.addSeats');
        Route::view('checkout/welcome', 'theme::welcome');

        Route::post('subscribe', '\Wave\Http\Controllers\SubscriptionController@subscribe')->name('wave.subscribe');
        Route::post('switch-plans', '\Wave\Http\Controllers\SubscriptionController@switchPlans')->name('wave.switch-plans');
    });

    // Throttled: a share link is looked up by an opaque token, so cap probing
    // (well above any legitimate rate of clicking shared links).
    Route::get('sosShared/{hash}', '\Wave\Http\Controllers\sosContentsController@sosShared')->middleware('throttle:30,1')->name('wave.sosShared');
    Route::get('sosSharedDir/{hash}', '\Wave\Http\Controllers\sosContentsController@sosSharedDir')->middleware('throttle:30,1')->name('wave.sosSharedDir');
});

Route::get('wave/theme/image/{theme_name}', '\Wave\Http\Controllers\ThemeImageController@show');
Route::get('wave/plugin/image/{plugin_name}', '\Wave\Http\Controllers\PluginImageController@show');
Route::redirect('admin/login', '/login');

Route::get('reset', Reset::class);

/***** Billing Routes *****/

// Primary Paddle webhook endpoint — all Paddle events handled here.
Route::post('paddle/webhook', '\Wave\Http\Controllers\Billing\Webhooks\PaddleWebhook@handler')
    ->middleware('paddle-webhook-signature')
    ->name('paddle.webhook');

// Legacy aliases kept for backward compatibility.
Route::post('webhook/paddle', '\Wave\Http\Controllers\Billing\Webhooks\PaddleWebhook@handler')->middleware('paddle-webhook-signature');
Route::post('webhook/paddle-v2', '\Wave\Http\Controllers\Billing\Webhooks\PaddleWebhook@handler')->middleware('paddle-webhook-signature');

// Stripe is preserved as a future billing-provider option but kept off the appliance build.
Route::middleware(DenyOnAppliance::class)->group(function () {
    Route::post('webhook/stripe', '\Wave\Http\Controllers\Billing\Webhooks\StripeWebhook@handler');
    Route::get('stripe/portal', '\Wave\Http\Controllers\Billing\Stripe@redirect_to_customer_portal')->name('stripe.portal');
});
Route::redirect('billing', 'settings/subscription')
    ->middleware(DenyOnAppliance::class)
    ->name('billing');

Route::get('p/{page}', '\Wave\Http\Controllers\PageController@page');

try {
    if (User::first()) {
        /***** Dynamic Page Routes *****/
        foreach (Page::all() as $page) {
            Route::view($page->slug, 'theme::page', ['page' => $page->toArray()])->name($page->slug);
        }
    }
} catch (QueryException $e) {
    // Handle the exception or log it if needed
}
