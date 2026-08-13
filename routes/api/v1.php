<?php

use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\DriveAccountController;
use App\Http\Controllers\Api\V1\DriveFileController;
use App\Http\Controllers\Api\V1\EarningController;
use App\Http\Controllers\Api\V1\HandleController;
use App\Http\Controllers\Api\V1\InsightsApprovalController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\MyProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\PublicSpaceController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/plans', [SubscriptionController::class, 'plans'])->name('api.v1.plans');

// Midtrans calls this, not a browser: no auth, no CSRF (api routes have
// none), and the sha512 signature is the authentication.
Route::post('/webhooks/midtrans', MidtransWebhookController::class)
    ->name('api.v1.webhooks.midtrans');

Route::get('/handles/availability', [HandleController::class, 'availability'])
    ->middleware('throttle:30,1')
    ->name('api.v1.handles.availability');
Route::get('/profiles/{handle}', [PublicProfileController::class, 'show'])
    ->name('api.v1.profiles.show');
Route::get('/profiles/{handle}/spaces/{slug}', [PublicSpaceController::class, 'show'])
    ->name('api.v1.profiles.spaces.show');
Route::post('/profiles/{handle}/spaces/{slug}/unlock', [PublicSpaceController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('api.v1.profiles.spaces.unlock');

// The client approving in someone else's Space — the `client` guard, not
// the creator's. Same gates as the viewer: 404 → 410 → 423.
Route::middleware('auth:client')->group(function () {
    Route::post('/profiles/{handle}/spaces/{slug}/approvals', [ApprovalController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('api.v1.approvals.store');
    Route::post('/profiles/{handle}/spaces/{slug}/approvals/all', [ApprovalController::class, 'storeAll'])
        ->middleware('throttle:20,1')
        ->name('api.v1.approvals.store-all');

    // Buying. The buyer is the same identity that approves files.
    Route::post('/orders', [OrderController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.v1.orders.store');
    Route::get('/orders/mine', [OrderController::class, 'mine'])->name('api.v1.orders.mine');
});

// Authenticated (Sanctum SPA cookie)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', MeController::class)->name('api.v1.me');
    Route::post('/handles', [HandleController::class, 'claim'])
        ->middleware('throttle:10,1')
        ->name('api.v1.handles.claim');
    Route::get('/me/profile', [MyProfileController::class, 'show'])->name('api.v1.me.profile.show');
    Route::patch('/me/profile', [MyProfileController::class, 'update'])->name('api.v1.me.profile.update');

    // Crefile — Drive accounts & file browser
    Route::get('/drive-accounts', [DriveAccountController::class, 'index'])->name('api.v1.drive-accounts.index');
    Route::post('/drive-accounts/{driveAccount}/pick', [DriveAccountController::class, 'pick'])
        ->middleware('throttle:30,1')
        ->name('api.v1.drive-accounts.pick');
    Route::get('/drive-accounts/{driveAccount}/picker-token', [DriveAccountController::class, 'pickerToken'])
        ->middleware('throttle:20,1')
        ->name('api.v1.drive-accounts.picker-token');
    Route::delete('/drive-accounts/{driveAccount}', [DriveAccountController::class, 'destroy'])->name('api.v1.drive-accounts.destroy');
    Route::get('/files', [DriveFileController::class, 'index'])->name('api.v1.files.index');
    Route::get('/files/{driveFile}', [DriveFileController::class, 'show'])->name('api.v1.files.show');

    // Spaces
    Route::get('/spaces', [SpaceController::class, 'index'])->name('api.v1.spaces.index');
    Route::post('/spaces', [SpaceController::class, 'store'])->name('api.v1.spaces.store');
    Route::get('/spaces/{space}', [SpaceController::class, 'show'])->name('api.v1.spaces.show');
    Route::patch('/spaces/{space}', [SpaceController::class, 'update'])->name('api.v1.spaces.update');
    Route::post('/spaces/{space}/publish', [SpaceController::class, 'publish'])->name('api.v1.spaces.publish');
    Route::post('/spaces/{space}/unpublish', [SpaceController::class, 'unpublish'])->name('api.v1.spaces.unpublish');
    Route::post('/spaces/{space}/archive', [SpaceController::class, 'archive'])->name('api.v1.spaces.archive');
    Route::post('/spaces/{space}/reactivate', [SpaceController::class, 'reactivate'])->name('api.v1.spaces.reactivate');
    Route::post('/spaces/{space}/duplicate', [SpaceController::class, 'duplicate'])->name('api.v1.spaces.duplicate');
    Route::delete('/spaces/{space}', [SpaceController::class, 'destroy'])->name('api.v1.spaces.destroy');

    // Approval — the owner's side
    Route::get('/insights/approvals', [InsightsApprovalController::class, 'index'])->name('api.v1.insights.approvals');
    Route::post('/approvals/{approval}/reply', [InsightsApprovalController::class, 'reply'])->name('api.v1.approvals.reply');
    Route::post('/spaces/{space}/approvals/reset', [InsightsApprovalController::class, 'reset'])->name('api.v1.approvals.reset');

    // Selling — offers the creator owns
    Route::get('/offers', [OfferController::class, 'index'])->name('api.v1.offers.index');
    Route::post('/offers', [OfferController::class, 'store'])->name('api.v1.offers.store');
    Route::patch('/offers/{offer}', [OfferController::class, 'update'])->name('api.v1.offers.update');
    Route::delete('/offers/{offer}', [OfferController::class, 'destroy'])->name('api.v1.offers.destroy');

    // Earnings — Insights → Orders
    Route::get('/me/earnings', [EarningController::class, 'summary'])->name('api.v1.me.earnings');
    Route::get('/me/orders', [EarningController::class, 'orders'])->name('api.v1.me.orders');
    Route::get('/me/withdrawals', [EarningController::class, 'withdrawals'])->name('api.v1.me.withdrawals');
    Route::post('/me/withdrawals', [EarningController::class, 'withdraw'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.withdrawals.store');

    // Subscription — Settings → Subscription
    Route::get('/me/subscription', [SubscriptionController::class, 'show'])->name('api.v1.me.subscription');
    Route::post('/me/subscription/checkout', [SubscriptionController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.subscription.checkout');
    Route::post('/me/subscription/cancel', [SubscriptionController::class, 'cancel'])->name('api.v1.me.subscription.cancel');
    Route::get('/me/invoices', [SubscriptionController::class, 'invoices'])->name('api.v1.me.invoices');

    // Bell, Needs Attention, and the five switches
    Route::get('/me/attention', [NotificationController::class, 'attention'])->name('api.v1.me.attention');
    Route::get('/me/notifications', [NotificationController::class, 'index'])->name('api.v1.me.notifications');
    Route::post('/me/notifications/read', [NotificationController::class, 'read'])->name('api.v1.me.notifications.read');
    Route::get('/me/notification-preferences', [NotificationController::class, 'preferences'])->name('api.v1.me.notification-preferences');
    Route::patch('/me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('api.v1.me.notification-preferences.update');
});
