<?php

declare(strict_types=1);

namespace MiraFive;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use MiraFive\Flags\MiraFlags;
use MiraFive\Http\CurlTransport;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;
use WeakReference;

/**
 * Sends events for one server source. `track()` and `identify()` buffer and never throw for transport reasons; the
 * buffer is sent at `flushAt` events, on `flush()`, and when the request ends.
 */
final class Mira
{
    public const string VERSION = '0.5.0';

    public const string SDK = 'mirafive-php/'.self::VERSION;

    public const int MAX_BATCH_SIZE = 1000;

    public const string DEFAULT_HOST = 'https://events.mirafive.io';

    public readonly string $host;

    private readonly string $key;

    private readonly Transport $transport;

    private readonly Reporter $reporter;

    private readonly Breaker $breaker;

    private readonly Delivery $delivery;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private bool $shutdownRegistered = false;

    private ?MiraFlags $flags = null;

    /**
     * @param  string|false|null  $key  the source's secret key; null reads MIRAFIVE_SECRET_KEY
     * @param  string|null  $host  null reads MIRAFIVE_HOST, else https://events.mirafive.io
     * @param  (callable(MiraError): void)|null  $onError  receives every delivery failure
     * @param  CacheInterface|null  $cache  shares the flag document between PHP processes
     * @param  int  $flushDeadlineMs  what one flush may spend on attempts and waits together
     * @param  bool  $enabled  false: nothing leaves the process and no key is needed, but input is still checked
     * @param  bool  $flushOnShutdown  false when the framework flushes on terminate
     * @param  (Closure(string, string): void)|null  $handOff  receives buffered batches (body, batch id) instead of sending them
     * @param  (Closure(int): void)|null  $sleep  waits between retries, in milliseconds; for tests
     */
    public function __construct(
        string|false|null $key = null,
        ?string $host = null,
        public readonly Mode $mode = Mode::Full,
        private readonly int $flushAt = 100,
        int $timeoutMs = 5_000,
        private readonly int $connectTimeoutMs = 1_000,
        private readonly int $flushDeadlineMs = 3_000,
        int $maxRetries = 2,
        int $maxRetryAfterMs = 3_000,
        ?Transport $transport = null,
        ?callable $onError = null,
        ?LoggerInterface $logger = null,
        private readonly ?CacheInterface $cache = null,
        public readonly bool $enabled = true,
        private readonly bool $flushOnShutdown = true,
        private readonly int $flagsRefreshSeconds = 30,
        private readonly ?Closure $handOff = null,
        ?Closure $sleep = null,
    ) {
        if ($flushAt < 1 || $flushAt > self::MAX_BATCH_SIZE || min($timeoutMs, $connectTimeoutMs, $flushDeadlineMs) < 1 || $maxRetries < 0 || $maxRetryAfterMs < 0) {
            throw new InvalidArgumentException('flushAt is 1–1000, the timeouts and the deadline positive, maxRetries and maxRetryAfterMs at least 0.');
        }

        $this->key = Env::key($key);
        $this->host = Env::host($host);
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport);
        $this->reporter = new Reporter($onError, $logger);
        $this->breaker = new Breaker(new Store($cache, $this->host, $this->key), 'batch.breaker');
        $this->delivery = new Delivery(
            $this->transport,
            $this->host,
            $this->key,
            $timeoutMs,
            $connectTimeoutMs,
            $maxRetries,
            $maxRetryAfterMs,
            $sleep ?? static function (int $ms): void {
                usleep($ms * 1000);
            },
        );
    }

    public function __destruct()
    {
        $this->flush();
    }

    /**
     * Buffers one event. Pass your own pseudonymous user id, never an email address.
     *
     * @param  array<string, mixed>  $properties
     * @param  array{url?: string, title?: string, referrer?: string}|null  $page
     *
     * @throws InvalidArgumentException for input the collector would refuse, and for identifiers in consentless mode
     */
    public function track(
        string $name,
        ?string $userId = null,
        ?string $anonymousId = null,
        ?string $sessionId = null,
        array $properties = [],
        DateTimeInterface|int|null $time = null,
        ?array $page = null,
    ): void {
        $this->enqueue(Event::wire([
            'name' => $name,
            'userId' => $userId,
            'anonymousId' => $anonymousId,
            'sessionId' => $sessionId,
            'properties' => $properties,
            'time' => $time,
            'page' => $page,
        ], $this->mode, Clock::ms()));
    }

    /**
     * Buffers `$identify`: links the anonymous id, when given, to the user and records their traits. Full mode only.
     *
     * @param  array<string, mixed>  $traits
     */
    public function identify(string $userId, array $traits = [], ?string $anonymousId = null, DateTimeInterface|int|null $time = null): void
    {
        $this->track('$identify', userId: $userId, anonymousId: $anonymousId, properties: $traits, time: $time);
    }

    /**
     * Sends up to 1000 events now, as one batch, and returns the collector's receipt. The same idempotency key always
     * maps to the same batch id, so a repeated call is stored once.
     *
     * @param  list<array<string, mixed>>  $events  each with `name` and optionally `userId`, `anonymousId`, `sessionId`,
     *                                              `properties`, `time`, `page`, `id`
     *
     * @throws MiraError when the collector refuses the batch or cannot be reached after the retries
     * @throws InvalidArgumentException for input the collector would refuse
     */
    public function send(array $events, ?string $idempotencyKey = null): Receipt
    {
        if ($events === [] || count($events) > self::MAX_BATCH_SIZE) {
            throw new InvalidArgumentException('A batch carries 1–1000 events.');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('An idempotency key is a non-empty string (PROTOCOL §5).');
        }

        $now = Clock::ms();
        $wire = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                throw new InvalidArgumentException('Each event is an array with at least a name.');
            }

            $wire[] = Event::wire($event, $this->mode, $now);
        }

        $batchId = $idempotencyKey === null ? BatchId::random() : BatchId::fromIdempotencyKey($idempotencyKey);

        return $this->enabled ? $this->delivery->deliver($wire, $batchId, $this->mode) : new Receipt($batchId, count($wire), 0);
    }

    /**
     * Delivers a batch a hand-off encoded, with the usual retries. The key is this client's, never the message's.
     * Events the collector refuses are reported and dropped, and the rest is resent, as for buffered batches.
     *
     * @throws MiraError
     */
    public function deliverPrepared(string $body): Receipt
    {
        [$batchId, $count] = Delivery::inspect($body);

        return $this->enabled ? $this->deliverRecovering($body, $batchId, null) : new Receipt($batchId, $count, 0);
    }

    /**
     * Sends the buffer within `flushDeadlineMs`. Failures go to onError (or the logger); nothing is thrown. After a
     * failed flush, flushes skip the network for 30 s and drop their events.
     */
    public function flush(): void
    {
        $events = $this->buffer;
        $this->buffer = [];

        if ($events === []) {
            return;
        }

        if ($this->handOff === null && $this->breaker->isOpen()) {
            if ($this->breaker->firstSkip()) {
                $this->reporter->report(new MiraError('network_error', 'Delivery failed recently; events are dropped for up to 30 s without trying the network.', retryable: true));
            }

            return;
        }

        $this->deliverSplitting($events, new Budget($this->flushDeadlineMs));
    }

    /** The flags of this source, sharing key, host, transport and cache. Server-counted exposures go through this client. */
    public function flags(): MiraFlags
    {
        return $this->flags ??= new MiraFlags(
            key: $this->key,
            host: $this->host,
            refreshSeconds: $this->flagsRefreshSeconds,
            connectTimeoutMs: $this->connectTimeoutMs,
            cache: $this->cache,
            enabled: $this->enabled,
            mira: $this,
            transport: $this->transport,
            onError: $this->reporter->report(...),
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function enqueue(array $event): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->buffer[] = $event;

        if ($this->flushOnShutdown && ! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            // Weak, so a client dropped mid-request is flushed by its destructor and not kept alive until shutdown.
            $client = WeakReference::create($this);
            register_shutdown_function(static function () use ($client): void {
                $client->get()?->flush();
            });
        }

        if (count($this->buffer) >= $this->flushAt) {
            $this->flush();
        }
    }

    /**
     * A batch the collector finds too large is halved until it fits; one event never is.
     *
     * @param  list<array<string, mixed>>  $events
     */
    private function deliverSplitting(array $events, Budget $budget): void
    {
        try {
            $batchId = BatchId::random();
            $body = Delivery::encode($events, $batchId, $this->mode);

            if ($this->handOff === null) {
                $this->deliverRecovering($body, $batchId, $budget);
            } else {
                $this->handOver($this->handOff, $body, $batchId);
            }
        } catch (MiraError $error) {
            if ($error->errorCode !== 'payload_too_large' || count($events) === 1) {
                if ($error->retryable) {
                    $this->breaker->trip();
                }

                $this->reporter->report($error);

                return;
            }

            $half = intdiv(count($events), 2);
            $this->deliverSplitting(array_slice($events, 0, $half), $budget);
            $this->deliverSplitting(array_slice($events, $half), $budget);
        }
    }

    /**
     * One refused event must not cost its whole batch: on `validation_failed` the named events are reported and
     * dropped, and the rest is resent, at most three times.
     *
     * @throws MiraError
     */
    private function deliverRecovering(string $body, string $batchId, ?Budget $budget, int $round = 0): Receipt
    {
        try {
            return $this->delivery->post($body, $batchId, $budget);
        } catch (MiraError $error) {
            $rest = $round < 3 ? Delivery::withoutRefused($body, $error) : null;

            if ($rest === null) {
                throw $error;
            }

            $this->reporter->report($rest[2]);

            return $this->deliverRecovering($rest[0], $rest[1], $budget, $round + 1);
        }
    }

    /**
     * A hand-off that fails is reported like a failed delivery: flush() never throws.
     *
     * @param  Closure(string, string): void  $handOff
     */
    private function handOver(Closure $handOff, string $body, string $batchId): void
    {
        try {
            $handOff($body, $batchId);
        } catch (Throwable $exception) {
            $this->reporter->report(new MiraError('unexpected', 'The hand-off failed: '.$exception->getMessage(), previous: $exception));
        }
    }
}
