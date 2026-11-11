<?php

use App\Http\Controllers\Api\V1\AffiliateController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ApiKeyController;
use App\Http\Controllers\Api\V1\CaptionAssistController;
use App\Http\Controllers\Api\V1\ConnectedAppController;
use App\Http\Controllers\Api\V1\CreditsController;
use App\Http\Controllers\Api\V1\CreemWebhookController;
use App\Http\Controllers\Api\V1\DeliveryLogController;
use App\Http\Controllers\Api\V1\EnterpriseContactController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\FileVersionController;
use App\Http\Controllers\Api\V1\FolderController;
use App\Http\Controllers\Api\V1\GooglePlayBillingController;
use App\Http\Controllers\Api\V1\GooglePlayWebhookController;
use App\Http\Controllers\Api\V1\HandleController;
use App\Http\Controllers\Api\V1\InboxCommentController;
use App\Http\Controllers\Api\V1\InboxController;
use App\Http\Controllers\Api\V1\McpActivityController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MyProfileController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OutstandConnectController;
use App\Http\Controllers\Api\V1\PinterestBoardController;
use App\Http\Controllers\Api\V1\ProfileFontController;
use App\Http\Controllers\Api\V1\PublicApprovalController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\PublicSpaceController;
use App\Http\Controllers\Api\V1\SocialAccountController;
use App\Http\Controllers\Api\V1\SocialApprovalController;
use App\Http\Controllers\Api\V1\SocialFeedController;
use App\Http\Controllers\Api\V1\SocialPlanLinkController;
use App\Http\Controllers\Api\V1\SocialPostController;
use App\Http\Controllers\Api\V1\SocialSettingsController;
use App\Http\Controllers\Api\V1\SocialWebhookController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\SpaceEventController;
use App\Http\Controllers\Api\V1\StorageController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\TeamController;
use App\Http\Controllers\Api\V1\TourController;
use App\Http\Controllers\Api\V1\WaitlistController;
use App\Http\Controllers\OAuth\IntrospectionController;
use App\Http\Middleware\AcceptBeaconJson;
use App\Http\Middleware\CapTokenSends;
use App\Http\Middleware\EnforceTokenScope;
use App\Http\Middleware\EnsureInboxEnabled;
use App\Http\Middleware\MarketingSiteOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Public

// The MCP server asks here whether a bearer token is live. The X-MCP-Secret
// header is the authentication; the MCP server caches answers briefly.
Route::post('/oauth/introspect', IntrospectionController::class)
    ->middleware('throttle:600,1')
    ->name('api.v1.oauth.introspect');

Route::get('/plans', [SubscriptionController::class, 'plans'])->name('api.v1.plans');

// Enterprise is contact-sales only. Auth optional; the honeypot and the
// throttle keep it from being a spam relay to the sales inbox.
Route::post('/enterprise/contact', EnterpriseContactController::class)
    ->middleware('throttle:5,1')
    ->name('api.v1.enterprise.contact');

// Google Play real-time developer notifications (Pub/Sub push). No auth
// middleware: the request carries Pub/Sub's Google-signed OIDC token,
// checked by the controller.
Route::post('/webhooks/google-play', GooglePlayWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('api.v1.webhooks.google-play');

// Creem calls this, not a browser: no auth, no CSRF (api routes have
// none), and the HMAC-SHA256 raw-body signature is the authentication.
Route::post('/webhooks/creem', CreemWebhookController::class)
    ->name('api.v1.webhooks.creem');

// Post for Me calls this on account and post events: no auth, the shared
// secret header is the authentication.
Route::post('/webhooks/postforme', SocialWebhookController::class)
    ->defaults('provider', 'postforme')
    ->name('api.v1.webhooks.postforme');

// The same URL doubles as the project's OAuth return: the browser arrives
// here with GET after granting an account (the dashboard's redirect URL is
// this public tunnel), and is handed on to the connect page, which syncs.
// The provider's isSuccess/error params ride along — dropping them made a
// refused connection look like nothing happened.
Route::get('/webhooks/postforme', function (Request $request) {
    // Never social=connected next to a failure: that reads as a success.
    $failed = strtolower((string) $request->query('isSuccess')) === 'false' || $request->filled('error');
    $query = http_build_query(['social' => $failed ? 'failed' : 'connected'] + $request->only(['isSuccess', 'error']));

    return redirect(config('app.frontend_url').'/social/connect?'.$query);
})->name('api.v1.webhooks.postforme.return');

// Outstand calls this on post and account events: no auth, the
// X-Outstand-Signature HMAC of the raw body is the authentication.
Route::post('/webhooks/outstand', SocialWebhookController::class)
    ->defaults('provider', 'outstand')
    ->name('api.v1.webhooks.outstand');

// And the same URL with GET is the redirect_uri we hand Outstand per
// sign-in: the browser lands here, with a pending `session` for the
// networks that pick pages, and is handed on to the connect page.
Route::get('/webhooks/outstand', [OutstandConnectController::class, 'return'])
    ->middleware('throttle:30,1')
    ->name('api.v1.webhooks.outstand.return');

// Public waitlists (double opt-in). The form posts from creatif.space only;
// the confirm link is opened from the email.
Route::post('/waitlist', [WaitlistController::class, 'join'])
    ->middleware([MarketingSiteOrigin::class, 'throttle:5,1'])
    ->name('api.v1.waitlist.join');
Route::get('/waitlist/confirm', [WaitlistController::class, 'confirm'])
    ->middleware('throttle:30,1')
    ->name('api.v1.waitlist.confirm');
Route::get('/waitlist/status', [WaitlistController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('api.v1.waitlist.status');

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

// Approval link — anyone holding the token may look (no sign-in); deciding
// needs the invited approver signed in (below).
Route::get('/a/{token}', [PublicApprovalController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('api.v1.approval-links.show');

// Its decisions — approve, ask for changes, take back, approve all — need the
// invited approver signed in (ApproverGate: 401 sign_in_required, 403
// approver_mismatch). They keep the stateful-SPA layer, so the app's session
// cookie arrives, but are exempt from the CSRF header check (bootstrap/app.php)
// so the page can still send a last decision with navigator.sendBeacon as the
// tab closes. Cross-site requests carry no session: the cookie is SameSite=Lax
// and Sanctum only starts a session for our own origins — so a forged request
// is simply unauthenticated (401).
Route::middleware(AcceptBeaconJson::class)->group(function () {
    Route::post('/a/{token}/posts/{post}/decision', [PublicApprovalController::class, 'decide'])
        ->middleware('throttle:30,1')
        ->name('api.v1.approval-links.decide');
    Route::post('/a/{token}/posts/{post}/retract', [PublicApprovalController::class, 'retract'])
        ->middleware('throttle:30,1')
        ->name('api.v1.approval-links.retract');
    // Group approval (RF-043): every post the client was shown, in one request.
    Route::post('/a/{token}/approve-all', [PublicApprovalController::class, 'approveAll'])
        ->middleware('throttle:10,1')
        ->name('api.v1.approval-links.approve-all');
});

// Comments and the email sign-in request stay token-only: no session, no
// cookie login, no CSRF — nothing a cross-site request could ride on.
Route::withoutMiddleware([EnsureFrontendRequestsAreStateful::class])
    ->middleware(AcceptBeaconJson::class)
    ->group(function () {
        Route::post('/a/{token}/posts/{post}/comments', [PublicApprovalController::class, 'comment'])
            ->middleware('throttle:30,1')
            ->name('api.v1.approval-links.comment');
        // A one-time sign-in link by email; always 202 (never says whether
        // the address matched). Also throttled per link + address.
        Route::post('/a/{token}/email-link', [PublicApprovalController::class, 'emailLink'])
            ->middleware('throttle:5,1')
            ->name('api.v1.approval-links.email-link');
    });

// Content Plan share link — read-only, the token is the credential.
Route::get('/plan/{token}', [SocialPlanLinkController::class, 'show'])
    ->middleware('throttle:60,1')
    ->name('api.v1.plan-links.show');

// A referral link was opened. Answers 204 whatever the code was: an
// unknown one must not tell a stranger which codes exist.
Route::post('/referrals/{code}/click', [AffiliateController::class, 'click'])
    ->middleware('throttle:30,1')
    ->name('api.v1.referrals.click');

// The analytics beacon. Deliberately unguarded: it is called from the
// visitor's browser, and most visitors are strangers.
Route::post('/spaces/{space}/events', SpaceEventController::class)
    ->middleware('throttle:60,1')
    ->name('api.v1.spaces.events');

// Session only (Sanctum SPA cookie): a bearer token never manages tokens.
Route::middleware('auth:sanctum')->group(function () {
    // Settings → API keys (personal keys for MCP clients)
    Route::get('/me/api-keys', [ApiKeyController::class, 'index'])->name('api.v1.me.api-keys.index');
    Route::post('/me/api-keys', [ApiKeyController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.api-keys.store');
    Route::delete('/me/api-keys/{apiKey}', [ApiKeyController::class, 'destroy'])->name('api.v1.me.api-keys.destroy');

    // Settings → Connected apps (OAuth clients the user authorized)
    Route::get('/me/connected-apps', [ConnectedAppController::class, 'index'])->name('api.v1.me.connected-apps.index');
    Route::delete('/me/connected-apps/{clientId}', [ConnectedAppController::class, 'destroy'])->name('api.v1.me.connected-apps.destroy');

    // What connected apps and API keys did — never the content.
    Route::get('/me/mcp-activity', McpActivityController::class)->name('api.v1.me.mcp-activity');
});

// Google Play Billing: the Android app hands over a Play purchase.
Route::post('/billing/google/verify', [GooglePlayBillingController::class, 'verify'])
    ->middleware(['auth:sanctum', 'throttle:20,1'])
    ->name('api.v1.billing.google.verify');

// Authenticated: Sanctum SPA cookie, or a bearer token (OAuth access token
// or personal API key) — which EnforceTokenScope fences to config('mcp.routes').
Route::middleware(['auth:sanctum,mcp', EnforceTokenScope::class])->group(function () {
    Route::get('/me', MeController::class)->name('api.v1.me');
    Route::post('/me/tour', TourController::class)->name('api.v1.me.tour');
    Route::post('/handles', [HandleController::class, 'claim'])
        ->middleware('throttle:10,1')
        ->name('api.v1.handles.claim');
    Route::patch('/me/handle', [HandleController::class, 'change'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.handle.change');
    Route::get('/me/profile', [MyProfileController::class, 'show'])->name('api.v1.me.profile.show');
    Route::get('/me/social-settings', [SocialSettingsController::class, 'show'])->name('api.v1.me.social-settings.show');
    Route::patch('/me/social-settings', [SocialSettingsController::class, 'update'])->name('api.v1.me.social-settings.update');
    Route::patch('/me/profile', [MyProfileController::class, 'update'])->name('api.v1.me.profile.update');
    Route::post('/me/profile/font', [ProfileFontController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.profile.font');

    // Social — accounts connected through the relay (Outstand / Post for Me)
    // Credits — one balance for sends and storage
    Route::get('/credits', [CreditsController::class, 'show'])->name('api.v1.credits.show');
    Route::get('/credits/ledger', [CreditsController::class, 'ledger'])->name('api.v1.credits.ledger');
    Route::post('/credits/top-up', [CreditsController::class, 'topUp'])
        ->middleware('throttle:10,1')
        ->name('api.v1.credits.top-up');
    // Storage rental (POST/DELETE /credits/storage-blocks) was removed in
    // Media v2 (11 Nov 2026): the routes are gone, so both answer 404.

    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('api.v1.social-accounts.index');
    Route::post('/social-accounts/auth-url', [SocialAccountController::class, 'authUrl'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.auth-url');
    Route::post('/social-accounts/claim', [SocialAccountController::class, 'claim'])
        ->middleware('throttle:10,1')
        ->name('api.v1.social-accounts.claim');
    // Outstand only: the pages a Facebook / LinkedIn / Google sign-in offers.
    // The last connect's outcome, for an app that lost the browser to Outstand.
    Route::get('/social-accounts/connect-status', [OutstandConnectController::class, 'status'])
        ->middleware('throttle:60,1')
        ->name('api.v1.social-accounts.connect-status');
    Route::get('/social-accounts/pending', [OutstandConnectController::class, 'pending'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.pending');
    Route::post('/social-accounts/pending', [OutstandConnectController::class, 'finalize'])
        ->middleware('throttle:10,1')
        ->name('api.v1.social-accounts.pending.finalize');
    Route::post('/social-accounts/sync', [SocialAccountController::class, 'sync'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.sync');
    Route::get('/social-accounts/{socialAccount}/feed', [SocialFeedController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('api.v1.social-accounts.feed');
    Route::get('/social-accounts/{socialAccount}/pinterest-boards', PinterestBoardController::class)
        ->middleware('throttle:30,1')
        ->name('api.v1.social-accounts.pinterest-boards');
    Route::delete('/social-accounts/{socialAccount}', [SocialAccountController::class, 'destroy'])->name('api.v1.social-accounts.destroy');

    // Social — posts relayed to the platforms
    Route::get('/social-posts', [SocialPostController::class, 'index'])->name('api.v1.social-posts.index');
    Route::post('/social-posts', [SocialPostController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-posts.store');
    Route::get('/social-posts/{socialPost}', [SocialPostController::class, 'show'])->name('api.v1.social-posts.show');
    Route::patch('/social-posts/{socialPost}', [SocialPostController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-posts.update');
    Route::post('/social-captions/assist', CaptionAssistController::class)
        ->middleware('throttle:20,1')
        ->name('api.v1.social-captions.assist');
    Route::post('/social-posts/swap', [SocialPostController::class, 'swap'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-posts.swap');
    Route::get('/social-posts/{socialPost}/send-preview', [SocialPostController::class, 'sendPreview'])
        ->name('api.v1.social-posts.send-preview');
    Route::post('/social-posts/{socialPost}/send', [SocialPostController::class, 'send'])
        ->middleware(['throttle:30,1', CapTokenSends::class])
        ->name('api.v1.social-posts.send');
    Route::delete('/social-posts/{socialPost}/draft', [SocialPostController::class, 'discard'])
        ->name('api.v1.social-posts.discard');
    Route::post('/social-posts/{socialPost}/approve', [SocialApprovalController::class, 'approve'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-posts.approve');
    Route::get('/social-posts/{socialPost}/comments', [SocialApprovalController::class, 'comments'])
        ->name('api.v1.social-posts.comments.index');
    Route::post('/social-posts/{socialPost}/comments', [SocialApprovalController::class, 'comment'])
        ->middleware('throttle:60,1')
        ->name('api.v1.social-posts.comments.store');
    Route::post('/social-comments/{socialPostComment}/resolve', [SocialApprovalController::class, 'resolve'])
        ->name('api.v1.social-comments.resolve');
    Route::post('/social-comments/{socialPostComment}/apply', [SocialApprovalController::class, 'applySuggestion'])
        ->name('api.v1.social-comments.apply');
    Route::get('/social-posts/{socialPost}/approval-link', [SocialApprovalController::class, 'linkFor'])
        ->name('api.v1.social-posts.approval-link');
    Route::get('/social-approval-links', [SocialApprovalController::class, 'links'])
        ->name('api.v1.social-approval-links.index');
    Route::post('/social-approval-links', [SocialApprovalController::class, 'link'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-approval-links.store');
    Route::get('/social-approval-links/{socialApprovalLink}', [SocialApprovalController::class, 'show'])
        ->name('api.v1.social-approval-links.show');
    Route::patch('/social-approval-links/{socialApprovalLink}', [SocialApprovalController::class, 'update'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-approval-links.update');
    Route::post('/social-approval-links/{socialApprovalLink}/approve', [SocialApprovalController::class, 'approveAll'])
        ->middleware('throttle:10,1')
        ->name('api.v1.social-approval-links.approve');
    Route::delete('/social-approval-links/{socialApprovalLink}', [SocialApprovalController::class, 'revoke'])
        ->name('api.v1.social-approval-links.destroy');
    Route::get('/social-plan-links', [SocialPlanLinkController::class, 'index'])
        ->name('api.v1.social-plan-links.index');
    Route::post('/social-plan-links', [SocialPlanLinkController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.social-plan-links.store');
    Route::delete('/social-plan-links/{socialPlanLink}', [SocialPlanLinkController::class, 'destroy'])
        ->name('api.v1.social-plan-links.destroy');
    Route::delete('/social-posts/{socialPost}', [SocialPostController::class, 'destroy'])->name('api.v1.social-posts.destroy');

    // Inbox — DMs (Instagram) and comments (Instagram, Facebook) via Outstand.
    // Owner and team see (policies); INBOX_ENABLED=false answers 404.
    Route::prefix('inbox')->middleware(EnsureInboxEnabled::class)->group(function () {
        Route::get('/summary', [InboxController::class, 'summary'])
            ->middleware('throttle:120,1')
            ->name('api.v1.inbox.summary');
        Route::get('/conversations', [InboxController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.conversations.index');
        Route::get('/conversations/{conversation}', [InboxController::class, 'show'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.conversations.show');
        Route::patch('/conversations/{conversation}', [InboxController::class, 'update'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.conversations.update');
        Route::post('/conversations/{conversation}/messages', [InboxController::class, 'send'])
            ->middleware('throttle:30,1')
            ->name('api.v1.inbox.conversations.messages.store');
        Route::post('/conversations/{conversation}/read', [InboxController::class, 'read'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.conversations.read');
        Route::get('/comments', [InboxCommentController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.comments.index');
        Route::post('/comments/{comment}/reply', [InboxCommentController::class, 'reply'])
            ->middleware('throttle:30,1')
            ->name('api.v1.inbox.comments.reply');
        Route::post('/comments/{comment}/private-reply', [InboxCommentController::class, 'privateReply'])
            ->middleware('throttle:30,1')
            ->name('api.v1.inbox.comments.private-reply');
        Route::post('/comments/{comment}/read', [InboxCommentController::class, 'read'])
            ->middleware('throttle:60,1')
            ->name('api.v1.inbox.comments.read');
    });

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
    // Media v2: usage (Free {used_bytes, quota_bytes}; paid {keep_used_bytes,
    // keep_quota_bytes}). Before /files/{file} for the same reason as trash.
    Route::get('/files/usage', [FileController::class, 'usage'])->name('api.v1.files.usage');
    Route::delete('/files/trash', [FileController::class, 'emptyTrash'])->name('api.v1.files.trash.empty');
    Route::post('/files/trash/{ulid}/restore', [FileController::class, 'restore'])->name('api.v1.files.trash.restore');
    Route::delete('/files/trash/{ulid}', [FileController::class, 'forceDestroy'])->name('api.v1.files.trash.force');

    Route::get('/files/{file}', [FileController::class, 'show'])->name('api.v1.files.show');
    // Media v2: Keep — retention never deletes a kept file (paid, within quota).
    Route::post('/files/{file}/keep', [FileController::class, 'keep'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.keep');
    Route::delete('/files/{file}/keep', [FileController::class, 'unkeep'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.unkeep');
    /* The owner's own download. Public Spaces and Transfers have had one
       since the bucket went private; this is the same signed URL, minted
       for the person who uploaded the bytes. */
    Route::get('/files/{file}/download', [FileController::class, 'download'])
        ->middleware('throttle:60,1')
        ->name('api.v1.files.download');

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

    // Spaces
    Route::get('/spaces', [SpaceController::class, 'index'])->name('api.v1.spaces.index');
    Route::post('/spaces', [SpaceController::class, 'store'])->name('api.v1.spaces.store');
    Route::get('/spaces/{space}', [SpaceController::class, 'show'])->name('api.v1.spaces.show');
    Route::patch('/spaces/{space}', [SpaceController::class, 'update'])->name('api.v1.spaces.update');
    /* Append-only, unlike PATCH /spaces/{space} with an items array — that
       one reconciles, so "Add to Space" from the Files screen would have to
       send the whole document back. */
    Route::post('/spaces/{space}/items', [SpaceController::class, 'addItems'])
        ->middleware('throttle:60,1')
        ->name('api.v1.spaces.items.store');
    Route::post('/spaces/{space}/publish', [SpaceController::class, 'publish'])->name('api.v1.spaces.publish');
    Route::post('/spaces/{space}/unpublish', [SpaceController::class, 'unpublish'])->name('api.v1.spaces.unpublish');
    Route::post('/spaces/{space}/archive', [SpaceController::class, 'archive'])->name('api.v1.spaces.archive');
    Route::post('/spaces/{space}/reactivate', [SpaceController::class, 'reactivate'])->name('api.v1.spaces.reactivate');
    Route::post('/spaces/{space}/duplicate', [SpaceController::class, 'duplicate'])->name('api.v1.spaces.duplicate');
    Route::delete('/spaces/{space}', [SpaceController::class, 'destroy'])->name('api.v1.spaces.destroy');

    // Analytics — Insights → Analytics
    Route::get('/me/storage', StorageController::class)->name('api.v1.me.storage');
    Route::get('/me/storage/scan', [StorageController::class, 'scan'])->name('api.v1.me.storage.scan');
    Route::get('/me/analytics', AnalyticsController::class)->name('api.v1.me.analytics');

    // Proof of delivery. Deliberately not gated on a plan: "on every plan"
    // is what the screen promises, and evidence is not an upsell.
    Route::get('/me/deliveries', DeliveryLogController::class)->name('api.v1.me.deliveries');

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
    Route::post('/me/subscription/portal', [SubscriptionController::class, 'portal'])
        ->middleware('throttle:10,1')
        ->name('api.v1.me.subscription.portal');
    Route::get('/me/invoices', [SubscriptionController::class, 'invoices'])->name('api.v1.me.invoices');

    // "Waiting your approval": links sent to my (verified) email with posts
    // still waiting on my decision.
    Route::get('/me/approvals/waiting', [SocialApprovalController::class, 'waiting'])->name('api.v1.me.approvals.waiting');

    // Bell, Needs Attention, and the five switches
    Route::get('/me/attention', [NotificationController::class, 'attention'])->name('api.v1.me.attention');
    Route::get('/me/activity', [NotificationController::class, 'activity'])->name('api.v1.me.activity');
    Route::get('/me/notifications', [NotificationController::class, 'index'])->name('api.v1.me.notifications');
    Route::post('/me/notifications/read', [NotificationController::class, 'read'])->name('api.v1.me.notifications.read');
    Route::get('/me/notification-preferences', [NotificationController::class, 'preferences'])->name('api.v1.me.notification-preferences');
    Route::patch('/me/notification-preferences', [NotificationController::class, 'updatePreferences'])->name('api.v1.me.notification-preferences.update');
});
