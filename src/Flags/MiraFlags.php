<?php

declare(strict_types=1);

namespace MiraFive\Flags;

use InvalidArgumentException;
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

    private const int LOOKUP_TTL_MS = 60_000;

    private const int MAX_REMEMBERED = 10_000;

    private const int EXPOSURE_TTL_MS = 3_600_000;

    private readonly string $key;

    private readonly string $host;

    private readonly Transport $transport;

    private readonly Reporter $reporter;

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

    /** @var array<string, array{0: int, 1: Membership}> */
    private array $memberships = [];

    private int $lookupsFrom = 0;

    /** @var array<string, int> when each unit, experiment and variant was last counted */
    private array $exposed = [];

    /**
     * @param  string|false|null  $key  a server source's secret key; null reads MIRAFIVE_SECRET_KEY
     * @param  int  $refreshSeconds  at least 10
     * @param  string|array<array-key, mixed>|null  $document  a snapshot (JSON or decoded) used while none was fetched, if younger than 7 days
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
        private readonly ?CacheInterface $cache = null,
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
        $this->snapshot = match (true) {
            is_string($document) => Document::parse($document),
            is_array($document) => Document::parse(json_encode($document, JSON_THROW_ON_ERROR)),
            default => null,
        };
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

    /**
     * The document in use, decoded.
     *
     * @return array{at: int, flags: array<string, array<array-key, mixed>>}|null
     */
    public function snapshot(): ?array
    {
        $document = $this->current();

        return $document === null ? null : ['at' => $document->at, 'flags' => $document->flags];
    }

    /**
     * @return array{ready: bool, etag: string|null, fetchedAt: int|null}
     */
    public function status(): array
    {
        return ['ready' => $this->current() !== null, 'etag' => $this->etag, 'fetchedAt' => $this->fetchedAt];
    }

    private function current(): ?Document
    {
        $snapshot = $this->snapshot;

        return $this->document
            ?? ($snapshot !== null && self::now() - $snapshot->at < self::SNAPSHOT_MAX_AGE_MS ? $snapshot : null);
    }

    private function refresh(): void
    {
        if (! $this->enabled || $this->refused || ! $this->due()) {
            return;
        }

        $this->adoptCached();

        if ($this->due()) {
            $this->fetch();
            $this->storeCached();
        }
    }

    private function due(): bool
    {
        return $this->checkedAt === null || self::now() - $this->checkedAt >= $this->refreshMs;
    }

    private function fetch(): void
    {
        $this->checkedAt = self::now();

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

            $this->fetchedAt = self::now();
        } catch (MiraError $error) {
            $this->refused = $error->status === 401 || $error->status === 403;
            $this->reporter->report($error);
        }
    }

    /** Another process may have refreshed the shared copy more recently. */
    private function adoptCached(): void
    {
        $entry = $this->cacheGet();

        if ($entry === null || ($this->checkedAt !== null && $entry['checkedAt'] <= $this->checkedAt)) {
            return;
        }

        if ($entry['body'] !== null && ($entry['etag'] === null || $entry['etag'] !== $this->etag)) {
            $this->document = Document::parse($entry['body']) ?? $this->document;
        }

        $this->etag = $entry['etag'] ?? $this->etag;
        $this->fetchedAt = $entry['fetchedAt'] ?? $this->fetchedAt;
        $this->checkedAt = $entry['checkedAt'];
    }

    private function storeCached(): void
    {
        if ($this->cache === null) {
            return;
        }

        // Failures are shared too, so a MIRA FIVE outage costs one timeout per interval, not one per request.
        try {
            $this->cache->set($this->cacheKey(), [
                'body' => $this->document?->json,
                'etag' => $this->etag,
                'fetchedAt' => $this->fetchedAt,
                'checkedAt' => $this->checkedAt,
            ]);
        } catch (Throwable) {
            // A cache that cannot store only costs the next process a fetch.
        }
    }

    /**
     * @return array{body: string|null, etag: string|null, fetchedAt: int|null, checkedAt: int}|null
     */
    private function cacheGet(): ?array
    {
        try {
            $entry = $this->cache?->get($this->cacheKey());
        } catch (Throwable) {
            return null;
        }

        if (! is_array($entry) || ! is_int($entry['checkedAt'] ?? null)) {
            return null;
        }

        return [
            'body' => is_string($entry['body'] ?? null) ? $entry['body'] : null,
            'etag' => is_string($entry['etag'] ?? null) ? $entry['etag'] : null,
            'fetchedAt' => is_int($entry['fetchedAt'] ?? null) ? $entry['fetchedAt'] : null,
            'checkedAt' => $entry['checkedAt'],
        ];
    }

    private function cacheKey(): string
    {
        return 'mirafive.flags.'.substr(hash('sha256', $this->host.' '.$this->key), 0, 24);
    }

    /**
     * One POST per unit and minute; a failure answers "unavailable" and pauses lookups for a minute.
     *
     * @return Membership|'unavailable'
     */
    private function lookUp(?string $userId, ?string $anonymousId): Membership|string
    {
        $now = self::now();
        $unit = json_encode([$userId, $anonymousId], JSON_THROW_ON_ERROR);
        $known = $this->memberships[$unit] ?? null;

        if ($known !== null && $known[0] > $now) {
            return $known[1];
        }

        if ($now < $this->lookupsFrom) {
            return Facts::UNAVAILABLE;
        }

        try {
            $response = $this->call('POST', '/v1/flags/segments', $this->lookupTimeoutMs, ['Content-Type' => 'application/json'], json_encode(
                ['units' => [array_filter(['userId' => $userId, 'anonymousId' => $anonymousId], fn (?string $id): bool => $id !== null)]],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
            $membership = self::membership($response->json());
        } catch (MiraError $error) {
            $this->lookupsFrom = $now + max(self::LOOKUP_TTL_MS, $error->retryAfterMs ?? 0);
            $this->reporter->report($error);

            return Facts::UNAVAILABLE;
        }

        if (count($this->memberships) >= self::MAX_REMEMBERED) {
            array_shift($this->memberships);
        }

        $this->memberships[$unit] = [$now + self::LOOKUP_TTL_MS, $membership];

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
            ], $body, $timeoutMs);
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

    /** Once per unit, experiment and variant an hour in this process, stamped at read time. */
    private function expose(string $key, string $variant, ?string $userId, ?string $anonymousId): void
    {
        $now = self::now();
        $seen = json_encode([$key, $variant, $userId, $anonymousId], JSON_THROW_ON_ERROR);

        $countedAt = $this->exposed[$seen] ?? null;

        if (($countedAt !== null && $now - $countedAt < self::EXPOSURE_TTL_MS) || ($userId === null && $anonymousId === null)) {
            return;
        }

        if (count($this->exposed) >= self::MAX_REMEMBERED) {
            array_shift($this->exposed);
        }

        unset($this->exposed[$seen]);
        $this->exposed[$seen] = $now;
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

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
