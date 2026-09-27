<?php

declare(strict_types=1);

namespace MiraFive\Flags;

use InvalidArgumentException;
use MiraFive\Breaker;
use MiraFive\Clock;
use MiraFive\Delivery;
use MiraFive\Env;
use MiraFive\Http\CurlTransport;
use MiraFive\Http\Response;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use MiraFive\Http\TransportException;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Mode;
use MiraFive\Reporter;
use MiraFive\Store;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Evaluates flags on this server against the source's flag document (GET /v1/flags), refreshed on read once it is
 * older than `refreshSeconds`. Reads never throw: without a document every flag answers its code fallback.
 */
final class MiraFlags
{
    /** Send these on every response that carries a bootstrap: a shared cache would serve one person's flags to all. */
    public const array BOOTSTRAP_HEADERS = ['Cache-Control' => 'private, no-store'];

    private const int SNAPSHOT_MAX_AGE_MS = 7 * 24 * 3_600_000;

    private const int MEMBERSHIP_TTL_SECONDS = 60;

    private const int EXPOSURE_TTL_SECONDS = 3_600;

    private readonly string $key;

    private readonly string $host;

    private readonly Transport $transport;

    private readonly Reporter $reporter;

    private readonly Store $store;

    private readonly Breaker $lookups;

    private readonly int $refreshMs;

    private readonly ?Document $snapshot;

    private ?Document $document = null;

    private ?string $etag = null;

    /** When the document was last confirmed by MIRA FIVE, in epoch ms. */
    private ?int $fetchedAt = null;

    /** When a fetch was last attempted, successful or not. */
    private ?int $checkedAt = null;

    /** A refused key stays refused until the process restarts. */
    private bool $refused = false;

    /**
     * @param  string|false|null  $key  a server source's secret key; null reads MIRAFIVE_SECRET_KEY
     * @param  int  $refreshSeconds  at least 10
     * @param  CacheInterface|null  $cache  shares the document, segment memberships, the lookup back-off and exposure marks between processes
     * @param  string|array<array-key, mixed>|null  $document  a snapshot used while none was fetched, if younger than 7 days; snapshot() returns one
     * @param  bool  $enabled  false: never fetches or looks anything up, so reads answer their fallbacks (or the snapshot)
     * @param  Mira|null  $mira  sends exposures of experiments counted on your server; defaults to a client on the same key
     * @param  (callable(MiraError): void)|null  $onError
     */
    public function __construct(
        string|false|null $key = null,
        ?string $host = null,
        int $refreshSeconds = 30,
        private readonly int $timeoutMs = 1_500,
        private readonly int $lookupTimeoutMs = 500,
        private readonly int $connectTimeoutMs = 1_000,
        ?CacheInterface $cache = null,
        string|array|null $document = null,
        private readonly bool $enabled = true,
        private ?Mira $mira = null,
        ?Transport $transport = null,
        ?callable $onError = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->key = Env::key($key);
        $this->host = Env::host($host);
        $this->refreshMs = max(10, $refreshSeconds) * 1000;
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport);
        $this->reporter = new Reporter($onError, $logger);
        $this->store = new Store($cache, $this->host, $this->key);
        $this->lookups = new Breaker($this->store, 'lookup.breaker');
        $this->snapshot = $document === null ? null : $this->readSnapshot($document);
    }

    /**
     * The flags for one unit (FLAGS.md §5.1). Looks up segment membership when a flag tests segments.
     *
     * @param  string|null  $userId  your id for the signed-in person; the unit of flags assigned by person
     * @param  string|null  $anonymousId  MIRA FIVE's anonymous id from the browser SDK; the unit of flags assigned by browser
     * @param  array<string, mixed>  $properties  facts your rules test; held in memory, never sent
     * @param  array{experiments?: bool, targeting?: bool}  $consent  the banner's answer; an omitted scope means your own lawful basis applies
     * @param  bool  $optedOut  set it from Sec-GPC or DNT: no unit, no segment lookup, no exposure
     */
    public function for(
        ?string $userId = null,
        ?string $anonymousId = null,
        array $properties = [],
        array $consent = [],
        bool $optedOut = false,
    ): UserFlags {
        $unknown = array_diff(array_keys($consent), ['experiments', 'targeting']);

        if ($unknown !== []) {
            throw new InvalidArgumentException('consent takes experiments and targeting, not '.implode(', ', $unknown).'.');
        }

        $this->refresh();

        $document = $this->current();
        $experiments = ! $optedOut && ($consent['experiments'] ?? true) !== false;
        $targeting = ! $optedOut && ($consent['targeting'] ?? true) !== false;
        $userId = $optedOut ? null : Evaluator::usable($userId);
        // Without experiments consent the browser's anonymous id is not used at all.
        $anonymousId = $experiments && $anonymousId !== null ? Evaluator::usable(explode('.', $anonymousId)[0]) : null;
        $segments = match (true) {
            $document?->hasSegments() !== true => null,
            ! $this->enabled, ! $targeting, $userId === null && $anonymousId === null => Facts::UNAVAILABLE,
            default => $this->lookUp($userId, $anonymousId),
        };

        return new UserFlags(
            $document,
            new Facts($anonymousId, $userId, $properties, $segments),
            $this->fetchedAt ?? $document->at ?? 0,
            $this->expose(...),
            $experiments,
            $targeting,
        );
    }

    /** Whether a document is in hand, fetching one if due. */
    public function ready(): bool
    {
        $this->refresh();

        return $this->current() !== null;
    }

    /** The document in use, as the JSON MIRA FIVE sent; pass it back as `document:` to start from it. */
    public function snapshot(): ?string
    {
        return $this->current()?->json;
    }

    /**
     * @return array{ready: bool, etag: string|null, fetchedAt: int|null}
     */
    public function status(): array
    {
        return ['ready' => $this->current() !== null, 'etag' => $this->etag, 'fetchedAt' => $this->fetchedAt];
    }

    /**
     * @param  string|array<array-key, mixed>  $document
     */
    private function readSnapshot(string|array $document): ?Document
    {
        $json = is_string($document) ? $document : json_encode($document);
        $parsed = is_string($json) ? Document::parse($json) : null;

        if ($parsed === null) {
            $this->reporter->report(new MiraError('unexpected', 'The document snapshot is not a flag document this SDK can read; it is ignored.'));
        }

        return $parsed;
    }

    private function current(): ?Document
    {
        $snapshot = $this->snapshot;

        return $this->document
            ?? ($snapshot !== null && Clock::ms() - $snapshot->at < self::SNAPSHOT_MAX_AGE_MS ? $snapshot : null);
    }

    private function refresh(): void
    {
        if (! $this->enabled || $this->refused || ! $this->due()) {
            return;
        }

        $this->adoptShared();

        if ($this->due()) {
            $this->fetch();
            // Failures are shared too, so an outage costs one timeout per interval, not one per request.
            $this->store->set('document', [
                'body' => $this->document?->json,
                'etag' => $this->etag,
                'fetchedAt' => $this->fetchedAt,
                'checkedAt' => $this->checkedAt,
            ]);
        }
    }

    private function due(): bool
    {
        return $this->checkedAt === null || Clock::ms() - $this->checkedAt >= $this->refreshMs;
    }

    private function fetch(): void
    {
        $this->checkedAt = Clock::ms();

        try {
            $revalidate = $this->etag !== null && $this->document !== null ? ['If-None-Match' => $this->etag] : [];
            $response = $this->call('GET', '/v1/flags', $this->timeoutMs, $revalidate);

            if ($response->status !== 304) {
                $document = Document::parse($response->body);

                if ($document === null) {
                    throw new MiraError('unexpected', 'The flag document is in a format this SDK cannot read.', $response->status);
                }

                $this->document = $document;
                $this->etag = $response->header('etag');
            }

            $this->fetchedAt = Clock::ms();
        } catch (MiraError $error) {
            $this->refused = $error->status === 401 || $error->status === 403;
            $this->reporter->report($error);
        }
    }

    /** Another process may have refreshed the shared copy more recently. */
    private function adoptShared(): void
    {
        $entry = $this->store->get('document');

        if (! is_array($entry) || ! is_int($entry['checkedAt'] ?? null) || ($this->checkedAt !== null && $entry['checkedAt'] <= $this->checkedAt)) {
            return;
        }

        $body = is_string($entry['body'] ?? null) ? $entry['body'] : null;
        $etag = is_string($entry['etag'] ?? null) ? $entry['etag'] : null;

        if ($body !== null && ($etag === null || $etag !== $this->etag)) {
            $this->document = Document::parse($body) ?? $this->document;
        }

        $this->etag = $etag ?? $this->etag;
        $this->fetchedAt = is_int($entry['fetchedAt'] ?? null) ? $entry['fetchedAt'] : $this->fetchedAt;
        $this->checkedAt = $entry['checkedAt'];
    }

    /**
     * Answers are kept a minute. A failed lookup answers "unavailable" and skips lookups for 30 s (or Retry-After).
     *
     * @return Membership|'unavailable'
     */
    private function lookUp(?string $userId, ?string $anonymousId): Membership|string
    {
        $name = Store::hashed('segments', [$userId, $anonymousId]);
        $known = $this->store->get($name);

        if (is_array($known)) {
            return self::membership(['units' => [$known]]);
        }

        if ($this->lookups->isOpen()) {
            return Facts::UNAVAILABLE;
        }

        try {
            $response = $this->call('POST', '/v1/flags/segments', $this->lookupTimeoutMs, ['Content-Type' => 'application/json'], json_encode(
                ['units' => [array_filter(['userId' => $userId, 'anonymousId' => $anonymousId], fn (?string $id): bool => $id !== null)]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $membership = self::membership($response->json());
        } catch (MiraError $error) {
            $this->lookups->trip(max(Breaker::OPEN_MS, $error->retryAfterMs ?? 0));
            $this->reporter->report($error);

            return Facts::UNAVAILABLE;
        }

        $this->store->set($name, ['segments' => $membership->in, 'unavailable' => $membership->unavailable], self::MEMBERSHIP_TTL_SECONDS);

        return $membership;
    }

    private static function membership(mixed $answer): Membership
    {
        $unit = is_array($answer) && is_array($answer['units'] ?? null) ? ($answer['units'][0] ?? null) : null;

        if (! is_array($unit) || ! is_array($unit['segments'] ?? null) || ! is_array($unit['unavailable'] ?? null)) {
            throw new MiraError('unexpected', 'The segment lookup answered in a format this SDK cannot read.');
        }

        return new Membership(
            array_values(array_filter($unit['segments'], is_string(...))),
            array_values(array_filter($unit['unavailable'], is_string(...))),
        );
    }

    /**
     * Origin and Sec-Fetch-Site are never sent: MIRA FIVE marks a secret key that arrives with either as exposed.
     *
     * @param  array<string, string>  $headers
     *
     * @throws MiraError
     */
    private function call(string $method, string $path, int $timeoutMs, array $headers, ?string $body = null): Response
    {
        if ($this->key === '') {
            throw new MiraError('unauthorized', 'No secret key: pass one to MiraFlags or set MIRAFIVE_SECRET_KEY.');
        }

        try {
            $response = $this->transport->request($method, $this->host.$path, [
                ...$headers,
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'User-Agent' => Mira::SDK.' (PHP '.PHP_VERSION.')',
            ], $body, $timeoutMs, min($this->connectTimeoutMs, $timeoutMs));
        } catch (TransportException $exception) {
            throw new MiraError($exception->timedOut ? 'timeout' : 'network_error', $exception->getMessage(), retryable: true, previous: $exception);
        } catch (Throwable $exception) {
            throw new MiraError('unexpected', $exception->getMessage(), previous: $exception);
        }

        if ($response->status >= 400 || $response->status < 200) {
            throw Delivery::refusal($response);
        }

        return $response;
    }

    /** Once per unit, experiment and variant an hour, across processes when a cache is shared, stamped at read time. */
    private function expose(string $key, string $variant, ?string $userId, ?string $anonymousId): void
    {
        $seen = Store::hashed('exposed', [$key, $variant, $userId, $anonymousId]);

        if (($userId === null && $anonymousId === null) || $this->store->get($seen) !== null) {
            return;
        }

        $this->store->set($seen, 1, self::EXPOSURE_TTL_SECONDS);
        $mira = $this->mira ??= new Mira(key: $this->key, host: $this->host, transport: $this->transport, onError: $this->reporter->report(...), enabled: $this->enabled);

        if ($mira->mode === Mode::Consentless) {
            return;
        }

        try {
            $mira->track('$exposure', userId: $userId, anonymousId: $anonymousId, properties: ['$experiment' => $key, '$variant' => $variant]);
        } catch (InvalidArgumentException) {
            // A key or variant the collector would drop anyway.
        }
    }
}
