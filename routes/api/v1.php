<?php

use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\DeliveryLogController;
use App\Http\Controllers\Api\V1\DriveAccountController;
use App\Http\Controllers\Api\V1\EarningController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FileRequestController;
use App\Http\Controllers\Api\V1\FileVersionController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\HandleController;
use App\Http\Controllers\Api\V1\InsightsApprovalController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MidtransWebhookController;
use App\Http\Controllers\Api\V1\MyProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PostForMeWebhookController;
use App\Http\Controllers\Api\V1\PublicFileRequestController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\PublicSpaceController;
use App\Http\Controllers\Api\V1\PublicTransferController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\SocialPostController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SpaceEventController;
use App\Http\Controllers\Api\V1\StorageController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TransferController;
use Illuminate\Support\Facades\Route;

// Public
Route::get('/plans', [SubscriptionController::class, 'plans'])->name('api.v1.plans');

// Midtrans calls this, not a browser: no auth, no CSRF (api routes have
// none), and the sha512 signature is the authentication.
Route::post('/webhooks/midtrans', MidtransWebhookController::class)
    ->name('api.v1.webhooks.midtrans');

// Post for Me calls this on account and post events: no auth, the shared
// secret header is the authentication.
Route::post('/webhooks/postforme', PostForMeWebhookController::class)
    ->name('api.v1.webhooks.postforme');

// The same URL doubles as the project's OAuth return: the browser arrives
// here with GET after granting an account (the dashboard's redirect URL is
// this public tunnel), and is handed on to the connect page, which syncs.
Route::get('/webhooks/postforme', function () {
    return redirect(config('app.frontend_url').'/social/connect?social=connected');
})->name('api.v1.webhooks.postforme.return');

Route::get('/handles/availability', [HandleController::class, 'availability'])
    ->middleware('throttle:30,1')
    ->name('api.v1.handles.availability');
Route::get('/profiles/{handle}', [PublicProfileController::class, 'show'])
    ->name('api.v1.profiles.show');
Route::get('/profiles/{handle}/spaces/{slug}', [PublicSpaceController::class, 'show'])
    ->name('api.v1.profiles.spaces.show');
Route::get('/profiles/{handle}/spaces/{slug}/items/{item}/download', [PublicSpaceController::class, 'download'])
    ->middleware('throttle:60,1')
    ->name('api.v1.profiles.spaces.download');
Route::get('/profiles/{handle}/spaces/{slug}/archive', [PublicSpaceController::class, 'archive'])
    ->middleware('throttle:30,1')
    ->name('api.v1.profiles.spaces.archive');
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
/* The transfer page. No account by design, same as /r — a link is the
   whole product. */
Route::get('/t/{slug}', [PublicTransferController::class, 'show'])
    ->name('api.v1.public.transfers.show');
Route::post('/t/{slug}/unlock', [PublicTransferController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('api.v1.public.transfers.unlock');
Route::get('/t/{slug}/files/{file}/download', [PublicTransferController::class, 'download'])
    ->middleware('throttle:60,1')
    ->name('api.v1.public.transfers.download');
Route::get('/r/{slug}', [PublicFileRequestController::class, 'show'])
    ->name('api.v1.file-requests.public');
Route::post('/r/{slug}/unlock', [PublicFileRequestController::class, 'unlock'])
    ->middleware('throttle:10,1')
    ->name('api.v1.file-requests.unlock');
/* Optional auth: anyone may submit, and whoever is signed in gets the
   submission recorded against their account so it shows up under "Asked of
   you". The guard authenticates when a session is present and shrugs when
   it is not — it never gates. */
Route::post('/r/{slug}/submissions', [PublicFileRequestController::class, 'store'])
    ->middleware(['throttle:5,1', 'auth.optional'])
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

    // Social — accounts connected through Post for Me
    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('api.v1.social-accounts.index');
    Route::post('/social-accounts/auth-url', [SocialAccountController::class, 'authUrl'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.auth-url');
    Route::post('/social-accounts/sync', [SocialAccountController::class, 'sync'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.sync');
    Route::delete('/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('api.v1.social-accounts.destroy');

    // Social — posts relayed to the platforms
    Route::get('/social-posts', [SocialPostController::class, 'index'])->name('api.v1.social-posts.index');
    Route::post('/social-posts', [SocialPostController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-posts.store');
    Route::delete('/social-posts/{socialPost}', [SocialPostController::class, 'destroy'])->name('api.v1.social-posts.destroy');

    // Crefile — the file library (R2-hosted)
    Route::get('/files', [FileController::class, 'index'])->name('api.v1.files.index');
    Route::post('/folders', [FolderController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.folders.store');
    Route::patch('/folders/{folder}', [FolderController::class, 'update'])
        ->name('api.v1.folders.update');
    Route::delete('/folders/{folder}', [FolderController::class, 'destroy'])
        ->name('api.v1.folders.destroy');
    /* Declared before /files/{file} so "move" is never read as a ULID —
       the same reason the trash routes sit where they do. */
    Route::post('/files/move', [FileController::class, 'move'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.move');
    Route::post('/files/presign', [FileController::class, 'presign'])
        ->middleware('throttle:30,1')
        ->name('api.v1.files.presign');
    Route::post('/files/{file}/complete', [FileController::class, 'complete'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.complete');
    /* Trash. Declared before /files/{file} so "trash" is never read as a
       ULID by the route matcher. */
    Route::get('/files/trash', [FileController::class, 'trash'])->name('api.v1.files.trash');
    Route::delete('/files/trash', [FileController::class, 'emptyTrash'])->name('api.v1.files.trash.empty');
    Route::post('/files/trash/{ulid}/restore', [FileController::class, 'restore'])->name('api.v1.files.trash.restore');
    Route::delete('/files/trash/{ulid}', [FileController::class, 'forceDestroy'])->name('api.v1.files.trash.force');

    Route::get('/files/{file}', [FileController::class, 'show'])->name('api.v1.files.show');

    /* Replacing a file's bytes. Same two steps as an upload — the browser
       PUTs straight to storage — but the file keeps its id, so Spaces and
       links that already point at it stay pointed at it. */
    Route::get('/files/{file}/versions', [FileVersionController::class, 'index'])->name('api.v1.files.versions.index');
    Route::post('/files/{file}/versions/presign', [FileVersionController::class, 'presign'])
        ->middleware('throttle:30,1')
        ->name('api.v1.files.versions.presign');
    Route::post('/files/{file}/versions/complete', [FileVersionController::class, 'complete'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.versions.complete');
    Route::post('/files/{file}/versions/{version}/restore', [FileVersionController::class, 'restore'])
        ->middleware('throttle:30,1')
        ->name('api.v1.files.versions.restore');
    Route::delete('/files/{file}/versions/{version}', [FileVersionController::class, 'destroy'])->name('api.v1.files.versions.destroy');

    Route::delete('/files/{file}', [FileController::class, 'destroy'])->name('api.v1.files.destroy');

    // File Request — the owner's side
    // Transfers — files going out, as a link.
    Route::get('/transfers', [TransferController::class, 'index'])->name('api.v1.transfers.index');
    Route::get('/transfers/received', [TransferController::class, 'received'])->name('api.v1.transfers.received');
    Route::post('/transfers', [TransferController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.transfers.store');
    Route::patch('/transfers/{transfer}', [TransferController::class, 'update'])->name('api.v1.transfers.update');
    Route::delete('/transfers/{transfer}', [TransferController::class, 'destroy'])->name('api.v1.transfers.destroy');

    Route::get('/file-requests/asked', [FileRequestController::class, 'asked'])->name('api.v1.file-requests.asked');
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

    // Analytics — Insights → Analytics
    Route::get('/me/storage', StorageController::class)->name('api.v1.me.storage');
    Route::get('/me/storage/scan', [StorageController::class, 'scan'])->name('api.v1.me.storage.scan');
    Route::get('/me/analytics', AnalyticsController::class)->name('api.v1.me.analytics');

    // Proof of delivery. Deliberately not gated on a plan: "on every plan"
    // is what the screen promises, and evidence is not an upsell.
    Route::get('/me/deliveries', DeliveryLogController::class)->name('api.v1.me.deliveries');

    // Payouts — the affiliate wallet's side.
    Route::get('/me/withdrawals', [EarningController::class, 'withdrawals'])->name('api.v1.me.withdrawals');
    Route::post('/me/withdrawals', [EarningController::class, 'withdraw'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.withdrawals.store');
    Route::get('/me/payout-account', [EarningController::class, 'showPayoutAccount'])
        ->name('api.v1.me.payout-account.show');
    Route::put('/me/payout-account', [EarningController::class, 'savePayoutAccount'])
        ->name('api.v1.me.payout-account');

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
    Route::get('/me/activity', [NotificationController::class, 'activity'])->name('api.v1.me.activity');
    Route::get('/me/notifications', [NotificationController::class, 'index'])->name('api.v1.me.notifications');
    Route::post('/me/notifications/read', [NotificationController::class, 'read'])->name('api.v1.me.notifications.read');
    Route::get('/me/notification-preferences', [NotificationController::class, 'preferences'])->name('api.v1.me.notification-preferences');
    Route::patch('/me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('api.v1.me.notification-preferences.update');
});
