<?php

namespace App\OAuth;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Once;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Throwable;

/**
 * OAuth Client ID Metadata Documents, as the MCP authorization spec allows
 * next to Dynamic Client Registration: the client_id IS an https URL, and
 * the JSON document served there says who the client is (client_name) and
 * where it may be sent back (redirect_uris).
 *
 * The document is fetched (https only, public addresses only, no
 * redirects, 5 s, 5 KB), validated, and turned into an ordinary Passport
 * public client, so authorize / token / refresh / revoke work unchanged.
 * The copy is cached per the document's Cache-Control (5 min – 24 h,
 * default 1 h); after that the next request fetches it again and the
 * client's name and redirect URIs follow the document.
 */
class ClientMetadataDocuments
{
    public const MAX_BYTES = 5120;

    public const TIMEOUT_SECONDS = 5;

    public const MIN_TTL = 300;

    public const DEFAULT_TTL = 3600;

    public const MAX_TTL = 86400;

    /** @var Closure(string): (list<string>|false) host → its IP addresses */
    private Closure $resolveHost;

    /**
     * @param  (Closure(string): (list<string>|false))|null  $resolveHost
     */
    public function __construct(?Closure $resolveHost = null)
    {
        $this->resolveHost = $resolveHost ?? fn (string $host) => gethostbynamel($host);
    }

    /** Is this client_id a metadata document URL (and not a Passport client id)? */
    public static function isUrl(mixed $clientId): bool
    {
        if (! is_string($clientId) || strlen($clientId) > 2000 || ! str_starts_with($clientId, 'https://')) {
            return false;
        }
        $parts = parse_url($clientId);

        return $parts !== false
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['fragment'])
            // The spec requires a path; dot segments would make two URLs one client.
            && isset($parts['path']) && $parts['path'] !== '/'
            && ! preg_match('#/\.{1,2}(/|$)#', $parts['path']);
    }

    /** The document URL behind a Passport client, or null for a DCR / first-party client. */
    public static function urlFor(Client $client): ?string
    {
        $url = DB::table('oauth_client_metadata_documents')->where('client_id', $client->getKey())->value('url');

        return is_string($url) ? $url : null;
    }

    public static function hostFor(Client $client): ?string
    {
        $url = self::urlFor($client);

        return $url === null ? null : (string) parse_url($url, PHP_URL_HOST);
    }

    /**
     * The Passport client for this document URL: the cached one while fresh,
     * otherwise fetched and validated again.
     *
     * @throws ClientMetadataException
     */
    public function resolve(string $url): Client
    {
        if (! self::isUrl($url)) {
            throw new ClientMetadataException('client_id must be an https URL with a path.');
        }

        $hash = hash('sha256', $url);
        $row = DB::table('oauth_client_metadata_documents')->where('url_hash', $hash)->first();
        if ($row !== null && Carbon::parse((string) $row->expires_at)->isFuture()) {
            $client = Client::query()->find($row->client_id);
            if ($client !== null && ! $client->revoked) {
                return $client;
            }
        }

        [$document, $ttl] = $this->fetch($url);
        [$name, $redirects] = $this->validate($url, $document);

        $client = $row !== null ? Client::query()->find($row->client_id) : null;
        if ($client !== null && $client->revoked) {
            throw new ClientMetadataException('This client has been disabled.');
        }

        if ($client === null) {
            $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient($name, $redirects, false);
        } else {
            $client->forceFill(['name' => $name, 'redirect_uris' => $redirects])->save();
            // Passport memoizes client lookups with once() for the life of the
            // process: drop that, or a long-running worker keeps the old
            // redirect URIs after the document changed.
            Once::flush();
        }

        $now = now();
        $values = ['url' => $url, 'client_id' => $client->getKey(), 'fetched_at' => $now, 'expires_at' => $now->copy()->addSeconds($ttl), 'updated_at' => $now];
        try {
            if ($row === null) {
                DB::table('oauth_client_metadata_documents')->insert(['url_hash' => $hash, 'created_at' => $now] + $values);
                Log::info('oauth.client_metadata_document_registered', ['client_id' => $client->getKey(), 'host' => parse_url($url, PHP_URL_HOST)]);
            } else {
                DB::table('oauth_client_metadata_documents')->where('url_hash', $hash)->update($values);
            }
        } catch (QueryException) {
            // Another request registered the same URL a moment ago: use that one.
            $client->delete();
            $winner = DB::table('oauth_client_metadata_documents')->where('url_hash', $hash)->value('client_id');
            $client = $winner !== null ? Client::query()->find($winner) : null;
            if ($client === null) {
                throw new ClientMetadataException('The client metadata document could not be registered; try again.');
            }
        }

        return $client;
    }

    /**
     * @return array{0: array<string, mixed>, 1: int} the document and how long to cache it
     */
    private function fetch(string $url): array
    {
        $this->assertPublicHost((string) parse_url($url, PHP_URL_HOST));

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withOptions(['allow_redirects' => false])
                ->accept('application/json')
                ->get($url);
        } catch (Throwable) {
            throw new ClientMetadataException('The client metadata document could not be fetched.');
        }

        if ($response->status() !== 200) {
            throw new ClientMetadataException('The client metadata document answered HTTP '.$response->status().' (expected 200, no redirects).');
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_BYTES) {
            throw new ClientMetadataException('The client metadata document is larger than '.self::MAX_BYTES.' bytes.');
        }

        $document = json_decode($body, true);
        if (! is_array($document) || array_is_list($document)) {
            throw new ClientMetadataException('The client metadata document is not a JSON object.');
        }

        return [$document, $this->ttl((string) $response->header('Cache-Control'))];
    }

    private function ttl(string $cacheControl): int
    {
        if (preg_match('/(?:^|,)\s*no-store\b/i', $cacheControl)) {
            return self::MIN_TTL;
        }
        if (preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $m)) {
            return max(self::MIN_TTL, min(self::MAX_TTL, (int) $m[1]));
        }

        return self::DEFAULT_TTL;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{0: string, 1: list<string>} client name and redirect URIs
     */
    private function validate(string $url, array $document): array
    {
        if (($document['client_id'] ?? null) !== $url) {
            throw new ClientMetadataException('The document\'s client_id must equal its URL exactly.');
        }
        if (array_key_exists('client_secret', $document) || array_key_exists('client_secret_expires_at', $document)) {
            throw new ClientMetadataException('A client metadata document must not carry a client_secret.');
        }

        $method = $document['token_endpoint_auth_method'] ?? 'none';
        if ($method !== 'none') {
            throw new ClientMetadataException('Only token_endpoint_auth_method "none" (a public client with PKCE) is supported.');
        }

        // Extra grants/response types a client advertises (e.g. Claude's jwt-bearer) are ignored:
        // the token endpoint still only grants authorization_code and refresh_token.
        $grants = $document['grant_types'] ?? ['authorization_code'];
        if (! is_array($grants) || ! in_array('authorization_code', $grants, true)) {
            throw new ClientMetadataException('grant_types must include authorization_code.');
        }
        $responseTypes = $document['response_types'] ?? ['code'];
        if (! is_array($responseTypes) || ! in_array('code', $responseTypes, true)) {
            throw new ClientMetadataException('response_types must include code.');
        }

        $redirects = $document['redirect_uris'] ?? null;
        if (! is_array($redirects) || $redirects === [] || count($redirects) > 10 || ! array_is_list($redirects)) {
            throw new ClientMetadataException('redirect_uris must list 1 to 10 URIs.');
        }
        foreach ($redirects as $uri) {
            if (! is_string($uri) || strlen($uri) > 2000 || ! RedirectUris::acceptable($uri)) {
                throw new ClientMetadataException('Redirect URI not allowed: '.(is_string($uri) ? $uri : gettype($uri)).'. Use https, a loopback http URL, or an app scheme.');
            }
        }

        // Shown on the consent screen next to the document's host.
        $name = is_string($document['client_name'] ?? null) ? $document['client_name'] : '';
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '');
        $name = $name === '' ? (string) parse_url($url, PHP_URL_HOST) : mb_substr($name, 0, 120);

        return [$name, array_values(array_unique($redirects))];
    }

    /** No loopback, private, link-local or reserved addresses: the fetch must not reach our own network. */
    private function assertPublicHost(string $host): void
    {
        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolveHost)($host);

        if ($ips === false || $ips === []) {
            throw new ClientMetadataException('The client metadata document host does not resolve.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new ClientMetadataException('The client metadata document must be on a public address.');
            }
        }
    }
}
