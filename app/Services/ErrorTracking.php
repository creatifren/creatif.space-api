<?php

namespace App\Services;

use App\Exceptions\MediaRefused;
use App\Exceptions\NotEnoughCredits;
use App\Exceptions\PlanFeatureRequired;
use App\Exceptions\ProviderConnectRefused;
use App\Exceptions\ProviderRateLimited;
use App\Exceptions\PublishPreflightFailed;
use App\Exceptions\ScheduleBeyondHorizon;
use App\Jobs\SendAnalytics;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Unhandled exceptions and failed queue jobs → PostHog error tracking, as
 * `$exception` events through the Analytics queue path (off when
 * POSTHOG_API_KEY is empty, never blocking the request).
 *
 * What is sent: exception class, a scrubbed message, stack frames (file
 * relative to the app, line, function; in_app for app/), route template,
 * status, job class, release. Never request bodies, headers, query strings,
 * frame arguments, tokens or email addresses.
 *
 * The same fingerprint (class + where it was thrown) goes at most once a
 * minute, and nothing in here may throw: a broken reporter must not turn
 * one error into two.
 */
class ErrorTracking
{
    private const DEDUPE_PREFIX = 'posthog-exception:';

    private const MAX_FRAMES = 50;

    private const MAX_CHAIN = 3;

    /** Job class while a queue job runs, so its errors say which. */
    private static ?string $job = null;

    private static ?string $release = null;

    public static function jobStarted(?string $job): void
    {
        self::$job = $job;
    }

    public static function jobFinished(): void
    {
        self::$job = null;
    }

    /**
     * @param  array<string, string>  $context  extra non-personal properties (e.g. job)
     */
    public static function report(Throwable $e, array $context = []): void
    {
        try {
            if (self::skip($e) || ($context['job'] ?? self::$job) === SendAnalytics::class) {
                return;
            }

            $fingerprint = self::fingerprint($e);
            if (! Cache::add(self::DEDUPE_PREFIX.$fingerprint, 1, now()->addMinute())) {
                return;
            }

            app(Analytics::class)->captureException(self::distinctId(), self::properties($e, $fingerprint, $context));
        } catch (Throwable) {
            // Reporting is best effort; the original error is still logged.
        }
    }

    /**
     * Answers to the person, not failures: a plan limit, a format or size
     * the rules refuse, a post a network would refuse, a short balance, a
     * slot too far out. Logged at info / warning (bootstrap/app.php), never
     * sent to error tracking.
     *
     * @var array<class-string<Throwable>, string>
     */
    public const REFUSALS = [
        PlanFeatureRequired::class => 'info',
        PublishPreflightFailed::class => 'info',
        NotEnoughCredits::class => 'info',
        ScheduleBeyondHorizon::class => 'info',
        MediaRefused::class => 'warning',
    ];

    public static function skip(Throwable $e): bool
    {
        if (isset(self::REFUSALS[$e::class])) {
            return true;
        }

        if ($e instanceof ValidationException
            || $e instanceof AuthenticationException
            || $e instanceof ModelNotFoundException
            || $e instanceof ProviderRateLimited) {
            return true;
        }

        if ($e instanceof ProviderConnectRefused) {
            // The user's own refusal (wrong app password …) is not ours to fix.
            return ! $e->misconfigured;
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() === 404;
    }

    public static function fingerprint(Throwable $e): string
    {
        return sha1($e::class.'|'.self::relative($e->getFile()).':'.$e->getLine());
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    public static function properties(Throwable $e, string $fingerprint, array $context = []): array
    {
        $list = [];
        for ($current = $e, $depth = 0; $current !== null && $depth < self::MAX_CHAIN; $current = $current->getPrevious(), $depth++) {
            $list[] = [
                'type' => $current::class,
                'value' => self::scrub(self::message($current)),
                'mechanism' => ['type' => 'generic', 'handled' => false, 'synthetic' => false],
                'stacktrace' => ['type' => 'raw', 'frames' => self::frames($current)],
            ];
        }

        $route = self::route();
        $job = $context['job'] ?? self::$job;
        $user = self::distinctId();

        return array_filter([
            '$exception_list' => $list,
            '$exception_fingerprint' => $fingerprint,
            '$exception_level' => 'error',
            'source' => $route !== null ? 'http' : ($job !== null ? 'queue' : 'console'),
            'route' => $route,
            'method' => $route !== null ? request()->method() : null,
            // Correlates with the log lines and the X-Request-Id the caller got.
            'request_id' => $route !== null ? self::requestId() : null,
            // The only identity sent: the user's public ulid, never email or name.
            'user_ulid' => $user !== 'server' ? $user : null,
            'status' => self::status($e, $route !== null),
            'job' => $job,
            'environment' => (string) config('app.env'),
            'release' => self::release(),
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Oldest call first, the throw last (PostHog's order). No arguments.
     *
     * @return list<array{platform: string, lang: string, filename: string, lineno: int, function: string, in_app: bool}>
     */
    public static function frames(Throwable $e): array
    {
        $frames = [];
        $file = $e->getFile();
        $line = $e->getLine();
        foreach ($e->getTrace() as $call) {
            $frames[] = self::frame($file, $line, (isset($call['class']) ? $call['class'].($call['type'] ?? '::') : '').$call['function']);
            $file = $call['file'] ?? '';
            $line = $call['line'] ?? 0;
            if (count($frames) >= self::MAX_FRAMES) {
                break;
            }
        }
        if (count($frames) < self::MAX_FRAMES) {
            $frames[] = self::frame($file, $line, '{main}');
        }

        return array_reverse($frames);
    }

    /** @return array{platform: string, lang: string, filename: string, lineno: int, function: string, in_app: bool} */
    private static function frame(string $file, int $line, string $function): array
    {
        $relative = self::relative($file);

        return [
            'platform' => 'custom',
            'lang' => 'php',
            'filename' => $relative,
            'lineno' => $line,
            'function' => $function,
            'in_app' => str_starts_with($relative, 'app/'),
        ];
    }

    private static function relative(string $file): string
    {
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }

    /** A query error without its bindings: those are user data. */
    private static function message(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            $previous = $e->getPrevious()?->getMessage() ?? 'Query failed';

            return mb_substr($previous, 0, 300).' (SQL: '.mb_substr($e->getSql(), 0, 500).')';
        }

        return mb_substr($e->getMessage(), 0, 1000);
    }

    /** Emails, bearer tokens, long keys and secrets-in-URLs out of a message. */
    public static function scrub(string $text): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = (string) preg_replace('/\b(Bearer|Basic|Token)\s+[A-Za-z0-9._~+\/=\-]+/i', '$1 [redacted]', $text);
        $text = (string) preg_replace('/([?&](?:token|key|secret|signature|password|api_key|access_token)=)[^&\s"\']+/i', '$1[redacted]', $text);

        return (string) preg_replace('/\b[A-Za-z0-9_\-]{32,}\b/', '[redacted]', $text);
    }

    private static function distinctId(): string
    {
        try {
            // hasUser(): only a user already resolved — never a fresh query
            // while the database may be what just failed.
            if (Auth::hasUser()) {
                $ulid = Auth::user()?->getAttribute('ulid');

                return is_string($ulid) && $ulid !== '' ? $ulid : 'server';
            }
        } catch (Throwable) {
        }

        return 'server';
    }

    private static function requestId(): ?string
    {
        try {
            $id = request()->attributes->get('request_id');

            return is_string($id) ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The route template (api/v1/spaces/{space}), never the filled-in URL. */
    private static function route(): ?string
    {
        try {
            return app()->bound('request') ? request()->route()?->uri() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private static function status(Throwable $e, bool $http): ?int
    {
        return match (true) {
            $e instanceof HttpExceptionInterface => $e->getStatusCode(),
            $e instanceof ProviderConnectRefused => 422,
            $http => 500,
            default => null,
        };
    }

    /** APP_RELEASE, else the deployed checkout's HEAD, else none. */
    public static function release(): ?string
    {
        if (self::$release !== null) {
            return self::$release ?: null;
        }

        $release = (string) config('app.release');
        if ($release === '') {
            try {
                $head = @file_get_contents(base_path('.git/HEAD'));
                $head = is_string($head) ? trim($head) : '';
                if (str_starts_with($head, 'ref: ')) {
                    $ref = @file_get_contents(base_path('.git/'.substr($head, 5)));
                    $head = is_string($ref) ? trim($ref) : '';
                }
                $release = preg_match('/^[0-9a-f]{40}$/', $head) ? $head : '';
            } catch (Throwable) {
                $release = '';
            }
        }

        self::$release = $release;

        return $release ?: null;
    }

    /** Tests only. */
    public static function flushState(): void
    {
        self::$job = null;
        self::$release = null;
    }
}
