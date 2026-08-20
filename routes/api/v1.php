<?php

use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\DeliveryLogController;
use App\Http\Controllers\Api\V1\DriveAccountController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\EarningController;
use App\Http\Controllers\Api\V1\FileRequestController;
use App\Http\Controllers\Api\V1\HandleController;
use App\Http\Controllers\Api\V1\InsightsApprovalController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\MyProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OfferController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PublicFileRequestController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\PublicSpaceController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SpaceEventController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamController;
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

// A referral link was opened. Answers 204 whatever the code was: an
// unknown one must not tell a stranger which codes exist.
Route::post('/referrals/{code}/click', [AffiliateController::class, 'click'])
    ->middleware('throttle:30,1')
    ->name('api.v1.referrals.click');

// File Request — the link a stranger opens. No account by design, so the
// throttle and the size caps are the whole defence.
Route::get('/r/{slug}', [PublicFileRequestController::class, 'show'])
    ->name('api.v1.file-requests.public');
Route::post('/r/{slug}/submissions', [PublicFileRequestController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('api.v1.file-requests.submit');

// The analytics beacon. Deliberately unguarded: it is called from the
// visitor's browser, and most visitors are strangers. Adding auth:client
// here would silence it for nearly everyone — the guard still resolves on
// its own when the visitor happens to be signed in.
Route::post('/spaces/{space}/events', SpaceEventController::class)
    ->middleware('throttle:60,1')
    ->name('api.v1.spaces.events');

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

    // Crefile — Drive import accounts (one-way copy into R2, no live sync)
    Route::get('/drive-accounts', [DriveAccountController::class, 'index'])->name('api.v1.drive-accounts.index');
    Route::post('/drive-accounts/{driveAccount}/pick', [DriveAccountController::class, 'pick'])
        ->middleware('throttle:30,1')
        ->name('api.v1.drive-accounts.pick');
    Route::get('/drive-accounts/{driveAccount}/picker-token', [DriveAccountController::class, 'pickerToken'])
        ->middleware('throttle:20,1')
        ->name('api.v1.drive-accounts.picker-token');
    Route::delete('/drive-accounts/{driveAccount}', [DriveAccountController::class, 'destroy'])->name('api.v1.drive-accounts.destroy');

    // Crefile — the file library (R2-hosted)
    Route::get('/files', [FileController::class, 'index'])->name('api.v1.files.index');
    Route::post('/files/presign', [FileController::class, 'presign'])
        ->middleware('throttle:30,1')
        ->name('api.v1.files.presign');
    Route::post('/files/{file}/complete', [FileController::class, 'complete'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.complete');
    Route::get('/files/{file}', [FileController::class, 'show'])->name('api.v1.files.show');
    Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('api.v1.files.destroy');

    // File Request — the owner's side
    Route::get('/file-requests', [FileRequestController::class, 'index'])->name('api.v1.file-requests.index');
    Route::post('/file-requests', [FileRequestController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.v1.file-requests.store');
    Route::patch('/file-requests/{fileRequest}', [FileRequestController::class, 'update'])->name('api.v1.file-requests.update');
    Route::delete('/file-requests/{fileRequest}', [FileRequestController::class, 'destroy'])->name('api.v1.file-requests.destroy');

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

    // Analytics — Insights → Analytics
    Route::get('/me/analytics', AnalyticsController::class)->name('api.v1.me.analytics');

    // Earnings — Insights → Orders
    Route::get('/me/earnings', [EarningController::class, 'summary'])->name('api.v1.me.earnings');

    // Proof of delivery. Deliberately not gated on a plan: "on every plan"
    // is what the screen promises, and evidence is not an upsell.
    Route::get('/me/deliveries', DeliveryLogController::class)->name('api.v1.me.deliveries');
    Route::get('/me/orders', [EarningController::class, 'orders'])->name('api.v1.me.orders');
    Route::get('/me/withdrawals', [EarningController::class, 'withdrawals'])->name('api.v1.me.withdrawals');
    Route::post('/me/withdrawals', [EarningController::class, 'withdraw'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.withdrawals.store');

    // Team — Settings → Team. The owner's screen; a seat cannot invite.
    Route::get('/team/members', [TeamController::class, 'index'])->name('api.v1.team.index');
    Route::post('/team/members', [TeamController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('api.v1.team.store');
    Route::patch('/team/members/{teamMember}', [TeamController::class, 'update'])->name('api.v1.team.update');
    Route::delete('/team/members/{teamMember}', [TeamController::class, 'destroy'])->name('api.v1.team.destroy');

    // Affiliate — /referral, hidden by a 404 until somebody is approved
    Route::get('/me/affiliate', [AffiliateController::class, 'show'])->name('api.v1.me.affiliate');
    Route::post('/affiliates/apply', [AffiliateController::class, 'apply'])
        ->middleware('throttle:5,1')
        ->name('api.v1.affiliates.apply');

    // Subscription — Settings → Subscription
    Route::get('/me/subscription', [SubscriptionController::class, 'show'])->name('api.v1.me.subscription');
    Route::post('/me/subscription/checkout', [SubscriptionController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.subscription.checkout');
    Route::post('/me/subscription/seats', [SubscriptionController::class, 'seats'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.subscription.seats');
    Route::post('/me/subscription/cancel', [SubscriptionController::class, 'cancel'])->name('api.v1.me.subscription.cancel');
    Route::get('/me/invoices', [SubscriptionController::class, 'invoices'])->name('api.v1.me.invoices');

    // Bell, Needs Attention, and the five switches
    Route::get('/me/attention', [NotificationController::class, 'attention'])->name('api.v1.me.attention');
    Route::get('/me/notifications', [NotificationController::class, 'index'])->name('api.v1.me.notifications');
    Route::post('/me/notifications/read', [NotificationController::class, 'read'])->name('api.v1.me.notifications.read');
    Route::get('/me/notification-preferences', [NotificationController::class, 'preferences'])->name('api.v1.me.notification-preferences');
    Route::patch('/me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('api.v1.me.notification-preferences.update');
});
