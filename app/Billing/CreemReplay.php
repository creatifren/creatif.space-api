<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\WalletTransactionType;
use App\Models\CreditPurchase;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Wallet;
use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Creem TEST-mode webhook replay: each scenario sets up a throwaway account
 * the way our own checkout would, sends Creem's events (fixtures in
 * resources/billing/creem-replay, signed here with the webhook secret from
 * config — never from git) and checks the account's state afterwards.
 *
 * Webhooks go through $deliver: over HTTP to a staging API
 * (billing:replay-webhooks) or into this app in-process (tests, local). The
 * two plan-change scenarios call our checkout endpoint in-process with
 * Creem's answers stubbed: they test what we do with Creem's reply, and
 * nothing is ever sent to Creem.
 */
final class CreemReplay
{
    public const SCENARIOS = [
        'cancel_scheduled' => 'A Pro plan set to cancel stays Pro until the period ends',
        'change_plan_on_cancel_scheduled' => 'Pro → Max on a plan set to cancel: a clear 409 subscription_not_active, never a 500',
        'refund' => 'A full refund marks the invoice refunded, once',
        'credits_topup' => 'A paid Credits top-up adds the Credits and one ledger row, however often it is replayed',
        'change_plan_active' => 'Pro → Max on an active plan: accepted, then the Max payment makes the account Max',
        'checkout_trial' => 'Checkout with the trial product: trialing, nothing charged',
        'checkout_notrial' => 'Checkout with the NOTRIAL product: active and paid at once, never trialing',
    ];

    private const FIXTURES = 'resources/billing/creem-replay';

    /** @var list<array{check: string, ok: bool, detail: string}> */
    private array $checks = [];

    private string $scenario = '';

    private int $sequence = 0;

    /** @var array<string, list<array{0: int, 1: array<string, mixed>}>> Creem's answers by path suffix */
    private array $creemAnswers = [];

    /** @var list<string> paths of the Creem calls the app made */
    public array $creemCalls = [];

    private bool $stubbed = false;

    /**
     * @param  (Closure(string, string): int)|null  $deliver  sends a signed body, returns the HTTP status; null = in-process
     */
    public function __construct(private readonly string $run, private readonly ?Closure $deliver = null) {}

    /**
     * @return array{result: 'passed'|'failed'|'skipped', checks: list<array{check: string, ok: bool, detail: string}>, note: string|null}
     */
    public function run(string $scenario): array
    {
        if (! isset(self::SCENARIOS[$scenario])) {
            throw new InvalidArgumentException("Unknown scenario [{$scenario}].");
        }
        $this->scenario = $scenario;
        $this->checks = [];

        $missing = $this->missingConfig($scenario);
        if ($missing !== null) {
            return ['result' => 'skipped', 'checks' => [], 'note' => $missing];
        }

        try {
            $steps = [
                'cancel_scheduled' => $this->cancelScheduled(...),
                'change_plan_on_cancel_scheduled' => $this->changePlanOnCancelScheduled(...),
                'refund' => $this->refund(...),
                'credits_topup' => $this->creditsTopup(...),
                'change_plan_active' => $this->changePlanActive(...),
                'checkout_trial' => $this->checkoutTrial(...),
                'checkout_notrial' => $this->checkoutNotrial(...),
            ];
            $steps[$scenario]();
        } catch (Throwable $e) {
            $this->check('ran to the end', false, $e::class.': '.$e->getMessage());
        }

        $ok = $this->checks !== [] && collect($this->checks)->every(fn (array $c) => $c['ok']);

        return ['result' => $ok ? 'passed' : 'failed', 'checks' => $this->checks, 'note' => null];
    }

    // ── Scenarios ────────────────────────────────────────────────────────

    private function cancelScheduled(): void
    {
        $sub = $this->activeSubscription($this->user(), 'pro');
        $event = $this->event('subscription.scheduled_cancel', $this->subscriptionVars($sub));

        $this->check('scheduled_cancel accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $sub->refresh();
        $cancelledAt = $sub->cancelled_at;
        $this->check('cancel recorded', $cancelledAt !== null);
        $this->check('still active until the period ends', $sub->status === SubscriptionStatus::Active, $sub->status->value);
        $this->check('account still Pro', $this->planOf($sub->user_id) === 'pro', $this->planOf($sub->user_id));

        $this->check('replay accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $this->check('replay changes nothing', $sub->fresh()?->cancelled_at?->equalTo($cancelledAt) === true);
    }

    private function changePlanOnCancelScheduled(): void
    {
        $sub = $this->activeSubscription($this->user(), 'pro', cancelled: true);
        $notActive = ['error' => 'Bad Request', 'code' => 'subscription_not_active', 'message' => 'Subscription is not active'];
        $this->stubCreem([
            '/resume' => [[200, ['id' => $sub->creem_subscription_id, 'status' => 'active']]],
            '/upgrade' => [[400, $notActive]],
            '/cancel' => [[200, ['id' => $sub->creem_subscription_id]]],
        ]);

        [$status, $json] = $this->api($sub->user, 'POST', '/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly']);

        $this->check('answered 409, not 500', $status === 409, "HTTP {$status}");
        $this->check('code subscription_not_active', ($json['code'] ?? null) === 'subscription_not_active', (string) json_encode($json));
        $this->check('a message the app can show', is_string($json['message'] ?? null) && $json['message'] !== '');
        $sub->refresh();
        $this->check('still set to cancel (resume undone)', $sub->cancelled_at !== null);
        $this->check('account still Pro', $this->planOf($sub->user_id) === 'pro', $this->planOf($sub->user_id));
    }

    private function refund(): void
    {
        $sub = $this->activeSubscription($this->user(), 'pro');
        $invoice = $this->paidInvoice($sub, 999);
        $event = $this->event('refund.created', [
            'refund_id' => $this->id('ref'),
            'transaction_id' => (string) $invoice->creem_transaction_id,
            'amount' => 999,
        ]);

        $this->check('refund accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $this->check('invoice refunded', $invoice->fresh()?->status === InvoiceStatus::Refunded, (string) $invoice->fresh()?->status->value);
        $this->check('replay accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $this->check('still refunded', $invoice->fresh()?->status === InvoiceStatus::Refunded);
    }

    private function creditsTopup(): void
    {
        $user = $this->user();
        $purchase = CreditPurchase::query()->create([
            'user_id' => $user->id, 'units' => 1, 'credits' => 10_000, 'amount' => 100,
            'currency' => 'USD', 'status' => 'pending',
        ]);
        $before = Wallet::balance($user, Wallet::CREDITS);
        $event = $this->event('checkout.completed.credits', [
            'checkout_id' => $this->id('ch'),
            'request_id' => $purchase->ulid,
            'product' => (string) config('services.creem.products.credits'),
            'order_id' => $this->id('ord'),
            'amount' => 100,
        ]);

        $this->check('top-up accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $this->check('purchase paid', $purchase->fresh()?->status === 'paid', (string) $purchase->fresh()?->status);
        $this->check('balance +10,000 Credits', Wallet::balance($user, Wallet::CREDITS) === $before + 10_000,
            'balance '.Wallet::balance($user, Wallet::CREDITS).', before '.$before);

        $this->check('same event replayed: accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $event['id'] = $this->id('evt');
        $this->check('same checkout, new event id: accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $this->check('balance unchanged by replays', Wallet::balance($user, Wallet::CREDITS) === $before + 10_000,
            'balance '.Wallet::balance($user, Wallet::CREDITS));
        $rows = WalletTransaction::query()
            ->where('reference_type', 'credit_purchase')->where('reference_id', $purchase->id)
            ->where('type', WalletTransactionType::CreditsTopUp)->count();
        $this->check('one ledger row', $rows === 1, "{$rows} rows");
    }

    private function changePlanActive(): void
    {
        $sub = $this->activeSubscription($this->user(), 'pro');
        $this->paidInvoice($sub, 999);
        $this->stubCreem(['/upgrade' => [[200, ['id' => $sub->creem_subscription_id, 'status' => 'active']]]]);

        [$status, $json] = $this->api($sub->user, 'POST', '/api/v1/me/subscription/checkout', ['plan' => 'max', 'period' => 'monthly']);
        $this->check('change accepted (202)', $status === 202, "HTTP {$status}");
        $this->check('changing to Max', ($json['data']['changing_to']['plan'] ?? null) === 'max', (string) json_encode($json));
        $this->check('upgrade asked of Creem', in_array('/v1/subscriptions/'.$sub->creem_subscription_id.'/upgrade', $this->creemCalls, true),
            implode(', ', $this->creemCalls));

        $transaction = $this->id('tran');
        $event = $this->event('subscription.paid', [
            ...$this->subscriptionVars($sub),
            'product' => (string) config('services.creem.products.max_monthly'),
            'status' => 'active',
            'transaction_id' => $transaction,
            'amount' => (int) (Plan::query()->where('key', 'max')->first()?->priceFor('monthly') ?? 1999),
            'period_start' => now()->toIso8601String(),
            'period_end' => now()->addMonth()->toIso8601String(),
        ]);

        $this->check('Max payment accepted', ($s = $this->send($event)) === 200, "HTTP {$s}");
        $sub->refresh();
        $this->check('account now Max', $this->planOf($sub->user_id) === 'max', $this->planOf($sub->user_id));
        $this->check('active', $sub->status === SubscriptionStatus::Active, $sub->status->value);
        $this->check('Max payment on an invoice', Invoice::query()->where('creem_transaction_id', $transaction)->where('status', InvoiceStatus::Paid)->exists());
    }

    private function checkoutTrial(): void
    {
        [$sub, $invoice] = $this->pendingCheckout($this->user());
        $vars = [
            ...$this->subscriptionVars($sub),
            'checkout_id' => (string) $invoice->creem_checkout_id,
            'request_id' => $invoice->number,
            'product' => (string) config('services.creem.products.pro_monthly'),
            'status' => 'trialing',
            'period_start' => now()->toIso8601String(),
            'period_end' => now()->addDays(7)->toIso8601String(),
        ];

        $this->check('checkout accepted', ($s = $this->send($this->event('checkout.completed.subscription', $vars))) === 200, "HTTP {$s}");
        $sub->refresh();
        $this->check('trialing', $sub->status === SubscriptionStatus::Trialing, $sub->status->value);
        $this->check('linked to the Creem subscription', $sub->creem_subscription_id === $vars['subscription_id']);
        $this->check('account Pro during the trial', $this->planOf($sub->user_id) === 'pro', $this->planOf($sub->user_id));

        // Creem's $0 card check at the trial's start.
        $zero = $this->event('subscription.paid', [...$vars, 'transaction_id' => $this->id('tran'), 'amount' => 0]);
        $this->check('$0 card check accepted', ($s = $this->send($zero)) === 200, "HTTP {$s}");
        $this->check('still trialing', $sub->fresh()?->status === SubscriptionStatus::Trialing);
        $this->check('nothing charged', ! $sub->invoices()->where('status', InvoiceStatus::Paid)->exists());
    }

    private function checkoutNotrial(): void
    {
        [$sub, $invoice] = $this->pendingCheckout($this->user());
        $product = (string) config('services.creem.products.pro_monthly_notrial');
        $vars = [
            ...$this->subscriptionVars($sub),
            'checkout_id' => (string) $invoice->creem_checkout_id,
            'request_id' => $invoice->number,
            'product' => $product,
            'status' => 'active',
            'period_start' => now()->toIso8601String(),
            'period_end' => now()->addMonth()->toIso8601String(),
        ];

        $this->check('checkout accepted', ($s = $this->send($this->event('checkout.completed.subscription', $vars))) === 200, "HTTP {$s}");
        $this->check('not trialing', $sub->fresh()?->status !== SubscriptionStatus::Trialing, (string) $sub->fresh()?->status->value);

        $transaction = $this->id('tran');
        $paid = $this->event('subscription.paid', [...$vars, 'transaction_id' => $transaction, 'amount' => (int) $invoice->amount]);
        $this->check('first payment accepted', ($s = $this->send($paid)) === 200, "HTTP {$s}");
        $sub->refresh();
        $this->check('active', $sub->status === SubscriptionStatus::Active, $sub->status->value);
        $this->check('account Pro', $this->planOf($sub->user_id) === 'pro', $this->planOf($sub->user_id));
        $after = $invoice->fresh();
        $this->check('invoice paid', $after !== null && $after->status === InvoiceStatus::Paid && $after->creem_transaction_id === $transaction,
            (string) $after?->status->value);
    }

    // ── Setup, as our own checkout would leave it ────────────────────────

    private function user(): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Creem replay',
            'email' => "creem-replay+{$this->run}-".str_replace('_', '-', $this->scenario).'@example.com',
            'password' => Str::random(40),
            'email_verified_at' => now(),
        ])->save();

        return $user;
    }

    private function activeSubscription(User $user, string $plan, bool $cancelled = false): Subscription
    {
        $sub = new Subscription;
        $sub->forceFill([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('key', $plan)->value('id'),
            'provider' => 'creem',
            'billing_period' => 'monthly',
            'status' => SubscriptionStatus::Active,
            'seats' => 1,
            'current_period_start' => now()->subDays(5),
            'current_period_end' => now()->addDays(25),
            'cancelled_at' => $cancelled ? now()->subDay() : null,
            'creem_subscription_id' => $this->id('sub'),
            'creem_customer_id' => $this->id('cust'),
        ])->save();

        return $sub;
    }

    private function paidInvoice(Subscription $sub, int $amount): Invoice
    {
        $invoice = new Invoice;
        $invoice->forceFill([
            'subscription_id' => $sub->id,
            'number' => Invoice::nextNumber(),
            'amount' => $amount, 'currency' => 'USD',
            'status' => InvoiceStatus::Paid,
            'creem_transaction_id' => $this->id('tran'),
            'due_at' => now()->subDays(5), 'paid_at' => now()->subDays(5),
        ])->save();

        return $invoice;
    }

    /** @return array{0: Subscription, 1: Invoice} */
    private function pendingCheckout(User $user): array
    {
        $sub = new Subscription;
        $sub->forceFill([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('key', 'pro')->value('id'),
            'provider' => 'creem',
            'billing_period' => 'monthly',
            'seats' => 1,
            'status' => SubscriptionStatus::Expired,
        ])->save();

        $invoice = new Invoice;
        $invoice->forceFill([
            'subscription_id' => $sub->id,
            'number' => Invoice::nextNumber(),
            'amount' => (int) (Plan::query()->where('key', 'pro')->first()?->priceFor('monthly') ?? 999),
            'currency' => 'USD',
            'status' => InvoiceStatus::Pending,
            'due_at' => now()->addHours(Invoice::DUE_HOURS),
            'creem_checkout_id' => $this->id('ch'),
        ])->save();

        return [$sub, $invoice];
    }

    /** @return array<string, string> */
    private function subscriptionVars(Subscription $sub): array
    {
        return [
            'subscription_id' => $sub->creem_subscription_id ?? $this->id('sub'),
            'subscription_ulid' => $sub->ulid,
            'customer_id' => $sub->creem_customer_id ?? $this->id('cust'),
            'product' => (string) config('services.creem.products.pro_monthly'),
            'period_start' => ($sub->current_period_start ?? now())->toIso8601String(),
            'period_end' => ($sub->current_period_end ?? now()->addMonth())->toIso8601String(),
        ];
    }

    // ── Events, delivery, API ────────────────────────────────────────────

    /**
     * A fixture with its {{placeholders}} filled. A value that is only a
     * placeholder takes the variable's type (amounts stay integers).
     *
     * @param  array<string, string|int>  $vars
     * @return array<string, mixed>
     */
    public function event(string $fixture, array $vars): array
    {
        $path = base_path(self::FIXTURES."/{$fixture}.json");
        $template = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $vars = ['event_id' => $this->id('evt'), ...$vars];

        $fill = function (mixed $value) use (&$fill, $vars, $fixture): mixed {
            if (is_array($value)) {
                return array_map($fill, $value);
            }
            if (! is_string($value)) {
                return $value;
            }
            if (preg_match('/^\{\{(\w+)\}\}$/', $value, $m) === 1) {
                return $vars[$m[1]] ?? throw new RuntimeException("Fixture {$fixture} needs {{{$m[1]}}}.");
            }

            return preg_replace_callback('/\{\{(\w+)\}\}/', fn ($m) => (string) ($vars[$m[1]] ?? throw new RuntimeException("Fixture {$fixture} needs {{{$m[1]}}}.")), $value);
        };

        return $fill($template);
    }

    /**
     * Sign and send one event; the HTTP status it got.
     *
     * @param  array<string, mixed>  $event
     */
    public function send(array $event): int
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, (string) config('services.creem.webhook_secret'));

        if ($this->deliver !== null) {
            return ($this->deliver)($body, $signature);
        }

        return $this->handle(Request::create('/api/v1/webhooks/creem', 'POST', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CREEM_SIGNATURE' => $signature,
        ], content: $body))->getStatusCode();
    }

    /**
     * Call our API in-process as $user (the session guard, as the app's own
     * sign-in would).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function api(User $user, string $method, string $uri, array $data): array
    {
        Auth::guard('web')->setUser($user);
        try {
            $response = $this->handle(Request::create($uri, $method, server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], content: json_encode($data, JSON_THROW_ON_ERROR)));
        } finally {
            // Drops the guards, and the user set on them, for the next request.
            app('auth')->forgetGuards();
        }

        /** @var array<string, mixed> $json */
        $json = (array) json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), $json];
    }

    private function handle(Request $request): Response
    {
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * Answer the app's calls to Creem (by path suffix, in turn) instead of
     * Creem. Only Creem's hosts are stubbed; nothing reaches Creem.
     *
     * @param  array<string, list<array{0: int, 1: array<string, mixed>}>>  $answers
     */
    private function stubCreem(array $answers): void
    {
        $this->creemAnswers = $answers;
        $this->creemCalls = [];
        if ($this->stubbed) {
            return;
        }
        $this->stubbed = true;
        $seen = [];

        Http::fake(function (HttpRequest $r) use (&$seen) {
            $host = (string) parse_url($r->url(), PHP_URL_HOST);
            if (! str_ends_with($host, 'creem.io')) {
                return null;
            }
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->creemCalls[] = $path;
            foreach ($this->creemAnswers as $suffix => $list) {
                if (str_ends_with($path, $suffix)) {
                    $key = $this->scenario.$suffix;
                    $i = $seen[$key] = ($seen[$key] ?? -1) + 1;
                    [$status, $body] = $list[min($i, count($list) - 1)];

                    return Http::response($body, $status);
                }
            }

            return Http::response(['error' => 'Not stubbed by the replay', 'path' => $path], 599);
        });
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function planOf(int $userId): string
    {
        return (string) User::query()->findOrFail($userId)->plan()->key->value;
    }

    private function id(string $prefix): string
    {
        return $prefix.'_replay_'.$this->run.'_'.(++$this->sequence);
    }

    private function check(string $label, bool $ok, string $detail = ''): void
    {
        $this->checks[] = ['check' => $label, 'ok' => $ok, 'detail' => $ok ? '' : $detail];
    }

    /** Why a scenario cannot run here (a product id it needs is not set), or null. */
    private function missingConfig(string $scenario): ?string
    {
        $needs = match ($scenario) {
            'credits_topup' => ['credits'],
            'change_plan_active' => ['pro_monthly', 'max_monthly'],
            'checkout_notrial' => ['pro_monthly', 'pro_monthly_notrial'],
            default => ['pro_monthly'],
        };
        foreach ($needs as $key) {
            $value = config("services.creem.products.{$key}");
            if (! is_string($value) || $value === '') {
                return 'CREEM_PRODUCT_'.strtoupper($key).' is not set';
            }
        }

        return null;
    }
}
