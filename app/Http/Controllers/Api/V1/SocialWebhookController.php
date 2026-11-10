<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SocialProvider;
use App\Enums\SocialAccountStatus;
use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\SocialConversation;
use App\Models\SocialPost;
use App\Models\User;
use App\Support\InboxNotifier;
use App\Support\InboxStore;
use App\Support\ProductEvents;
use App\Support\ProviderFields;
use App\Support\SocialPostRelay;
use App\Support\SocialProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A relay calls this on account and post events — one route per relay,
 * the route says which. Public: the provider's signature (or shared
 * secret) is the authentication. Unknown events and unknown ids answer
 * 200 — a non-2XX makes the provider retry, and there is nothing a
 * redelivery of an unknown event could fix. Only rows that relay holds
 * are touched.
 */
class SocialWebhookController extends Controller
{
    private SocialProvider $provider;

    /** Inbox events: deduplicated in webhook_events, ignored while the Inbox is off. */
    private const INBOX = ['conversation', 'message', 'message.read', 'comment'];

    public function __invoke(Request $request, SocialProviders $providers, string $provider): JsonResponse
    {
        $this->provider = $providers->named($provider);

        if (! $this->provider->verifyWebhook($request)) {
            return response()->json(['message' => 'Invalid signature.'], 403);
        }

        foreach ($this->provider->webhookEvents($request) as $event) {
            if (in_array($event['type'], self::INBOX, true)) {
                $this->inbox($event);

                continue;
            }

            match ($event['type']) {
                'account' => $this->upsertAccount($event['data']),
                'account.lost' => $this->loseAccount($event['data']),
                'post' => $this->updatePost($event['data']),
                'result' => $this->recordResult($event['data']),
                default => null,
            };
        }

        return response()->json(['message' => 'ok']);
    }

    /**
     * An account was connected or changed. external_id carries our user's
     * ulid — an unknown one is someone else's workspace noise.
     *
     * @param  array<string, mixed>  $data
     */
    private function upsertAccount(array $data): void
    {
        $user = User::query()->where('ulid', (string) ($data['external_id'] ?? ''))->first();
        $platform = SocialPlatform::tryFrom((string) ($data['platform'] ?? ''));
        $fields = ProviderFields::account($data);

        if ($user === null || $platform === null || $fields === null) {
            return;
        }
        $providerId = $fields['provider_account_id'];

        $row = $user->socialAccounts()->updateOrCreate(
            [
                'provider' => $this->provider->name(),
                'platform' => $platform,
                'provider_account_id' => $providerId,
            ],
            [
                'username' => $fields['username'],
                'profile_photo_url' => $fields['profile_photo_url'],
                'status' => ($data['status'] ?? 'connected') === 'connected'
                    ? SocialAccountStatus::Connected
                    : SocialAccountStatus::Disconnected,
                'connected_at' => now(),
            ],
        );

        // The row is unique per user + account, so the sync that follows
        // finds it and does not count the connect a second time.
        if ($row->wasRecentlyCreated) {
            ProductEvents::channelConnected($user, $row);
        }
    }

    /**
     * The account's grant stopped working (Outstand: its token would not
     * refresh). It is Disconnected until the user connects it again.
     *
     * @param  array<string, mixed>  $data
     */
    private function loseAccount(array $data): void
    {
        $id = (string) ($data['id'] ?? '');

        if ($id === '') {
            return;
        }

        $lost = SocialAccount::query()
            ->where('provider', $this->provider->name())
            ->where('provider_account_id', $id)
            ->where('status', SocialAccountStatus::Connected)
            ->with('user')
            ->get();

        SocialAccount::query()
            ->where('provider', $this->provider->name())
            ->where('provider_account_id', $id)
            ->update(['status' => SocialAccountStatus::Disconnected]);

        $lost->pluck('user')->filter()->unique('id')->each(fn (User $u) => ProductEvents::channelsCount($u));
    }

    /**
     * The post itself moved. `processed` alone doesn't decide the outcome —
     * per-target results do — so it publishes only until the targets say
     * otherwise; recompute has the final word.
     *
     * @param  array<string, mixed>  $data
     */
    private function updatePost(array $data): void
    {
        $post = $this->findPost($data);

        if ($post === null) {
            return;
        }

        DB::transaction(function () use ($post, $data): void {
            if (($data['status'] ?? null) === 'processed'
                && $post->status === SocialPostStatus::Scheduled) {
                $post->status = SocialPostStatus::Published;
            }

            $post->raw_payload = $data;
            $post->save();

            $post->recomputeFromTargets();
        });
    }

    /**
     * One destination's outcome. Redeliveries overwrite the same values —
     * idempotent by construction.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordResult(array $data): void
    {
        $post = SocialPost::query()
            ->where('provider', $this->provider->name())
            ->where('provider_post_id', (string) ($data['post_id'] ?? ''))
            ->first();

        // The post's own target, not any row with the id: the same platform
        // account can sit under two of our users (one Disconnected).
        $account = $post?->targets()->with('account')->get()
            ->first(fn ($t) => $t->account->provider_account_id === (string) ($data['social_account_id'] ?? ''))
            ?->account;

        if ($post === null || $account === null) {
            return;
        }

        SocialPostRelay::applyResult($post, $account, $data, 'webhook');
    }

    /**
     * By provider post id, falling back to our own ulid sent as
     * external_id — covers a webhook racing the create response.
     *
     * @param  array<string, mixed>  $data
     */
    private function findPost(array $data): ?SocialPost
    {
        $providerId = (string) ($data['id'] ?? '');
        $externalId = (string) ($data['external_id'] ?? '');

        return SocialPost::query()
            ->where('provider', $this->provider->name())
            ->when($providerId !== '', fn ($q) => $q->where('provider_post_id', $providerId))
            ->when(
                $providerId === '' && $externalId !== '',
                fn ($q) => $q->where('ulid', $externalId),
            )
            ->when($providerId === '' && $externalId === '', fn ($q) => $q->whereRaw('1 = 0'))
            ->first();
    }

    /**
     * One Inbox event, once. The receipt and its writes commit together,
     * as with Creem: a handler that fails leaves no receipt, so the
     * provider's retry is not mistaken for a duplicate.
     *
     * @param  array{type: string, data: array<string, mixed>, id?: string}  $event
     */
    private function inbox(array $event): void
    {
        if (! config('services.inbox.enabled')) {
            return;
        }

        DB::transaction(function () use ($event): void {
            if (isset($event['id']) && ! DB::table('webhook_events')->insertOrIgnore([
                'source' => $this->provider->name(),
                'event_id' => substr($event['id'], 0, 64),
                'type' => substr('inbox.'.$event['type'], 0, 64),
                'payload' => json_encode($event['data'], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ])) {
                return;
            }

            match ($event['type']) {
                'conversation' => $this->startConversation($event['data']),
                'message' => $this->recordMessage($event['data']),
                'message.read' => $this->readMessages($event['data']),
                'comment' => $this->recordComment($event['data']),
                default => null,
            };
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function startConversation(array $data): void
    {
        $account = InboxStore::account($this->provider->name(), (string) ($data['social_account_id'] ?? ''));

        if ($account !== null) {
            InboxStore::upsertConversation($account, $data);
        } else {
            InboxStore::resolveConversation($this->provider, (string) ($data['id'] ?? ''), $data['network'] ?? null);
        }
    }

    /**
     * A DM in or out, or the outcome of one of ours. message.received
     * names no account — the thread does, so an unknown thread is looked
     * up at the provider. A new inbound message rings the owner's bell.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordMessage(array $data): void
    {
        $conversation = InboxStore::resolveConversation($this->provider, (string) ($data['conversation_id'] ?? ''), $data['network'] ?? null);

        if ($conversation === null) {
            return;
        }

        if (($data['contact_id'] ?? null) !== null && $conversation->contact_id === null) {
            $conversation->contact_id = (string) $data['contact_id'];
        }

        [, $inbound] = InboxStore::upsertMessage($conversation, $data, countUnread: true);

        if ($inbound) {
            // A DM arriving proves the account's grant includes messages.
            $conversation->account()->whereNull('messages_scope_at')->update(['messages_scope_at' => now()]);
            InboxNotifier::message($conversation->user, $conversation->ulid);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function readMessages(array $data): void
    {
        $conversation = SocialConversation::query()
            ->where('provider', $this->provider->name())
            ->where('provider_conversation_id', (string) ($data['conversation_id'] ?? ''))
            ->first();

        if ($conversation !== null) {
            InboxStore::markSeen($conversation, $data['read_at'] ?? null);
        }
    }

    /**
     * A comment on one of the account's posts. Our own replies come back
     * through here too and are dropped by InboxStore.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordComment(array $data): void
    {
        $account = InboxStore::account($this->provider->name(), (string) ($data['account_id'] ?? ''));

        if ($account === null || ! $account->supportsComments()) {
            return;
        }

        $comment = InboxStore::upsertComment($account, $data, (string) ($data['network'] ?? ''));

        if ($comment !== null && $comment->wasRecentlyCreated) {
            InboxNotifier::comment($account->user);
        }
    }
}
