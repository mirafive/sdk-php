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

    public readonly string $host;

    private readonly string $key;

    private readonly Transport $transport;

    private readonly Reporter $reporter;

    private readonly Delivery $delivery;

    /** @var list<array<string, mixed>> */
    private array $buffer = [];

    private bool $flushesOnShutdown = false;

    private ?MiraFlags $flags = null;

    /**
     * @param  string|false|null  $key  the source's secret key; null reads MIRAFIVE_SECRET_KEY
     * @param  string|null  $host  null reads MIRAFIVE_HOST, else https://events.mirafive.io
     * @param  (callable(MiraError): void)|null  $onError  receives every delivery failure
     * @param  CacheInterface|null  $cache  shares the flag document between PHP processes
     * @param  (Closure(int): void)|null  $sleep  waits between retries, in milliseconds; for tests
     */
    public function __construct(
        string|false|null $key = null,
        ?string $host = null,
        public readonly Mode $mode = Mode::Full,
        private readonly int $flushAt = 100,
        int $timeoutMs = 5_000,
        int $maxRetries = 2,
        int $maxRetryAfterMs = 3_000,
        ?Transport $transport = null,
        ?callable $onError = null,
        ?LoggerInterface $logger = null,
        private readonly ?CacheInterface $cache = null,
        ?Closure $sleep = null,
    ) {
        if ($flushAt < 1 || $flushAt > self::MAX_BATCH_SIZE || $timeoutMs < 1 || $maxRetries < 0 || $maxRetryAfterMs < 0) {
            throw new InvalidArgumentException('flushAt is 1–1000, timeoutMs positive, maxRetries and maxRetryAfterMs at least 0.');
        }

        $this->key = Env::key($key);
        $this->host = Env::host($host);
        $this->transport = $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport);
        $this->reporter = new Reporter($onError, $logger);
        $this->delivery = new Delivery(
            $this->transport,
            $this->host,
            $this->key,
            $timeoutMs,
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
        ], $this->mode, self::now()));
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

        $now = self::now();
        $wire = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                throw new InvalidArgumentException('Each event is an array with at least a name.');
            }

            $wire[] = Event::wire($event, $this->mode, $now);
        }

        $batchId = $idempotencyKey === null ? BatchId::random() : BatchId::fromIdempotencyKey($idempotencyKey);

        return $this->delivery->deliver($wire, $batchId, $this->mode);
    }

    /** Sends the buffer. Failures go to onError (or the logger); nothing is thrown. */
    public function flush(): void
    {
        $events = $this->buffer;
        $this->buffer = [];

        if ($events !== []) {
            $this->deliverSplitting($events);
        }
    }

    /** The flags of this source, sharing key, host, transport and cache. Server-counted exposures go through this client. */
    public function flags(): MiraFlags
    {
        return $this->flags ??= new MiraFlags(
            key: $this->key,
            host: $this->host,
            cache: $this->cache,
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
        $this->buffer[] = $event;

        if (! $this->flushesOnShutdown) {
            $this->flushesOnShutdown = true;
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
    private function deliverSplitting(array $events): void
    {
        try {
            $this->delivery->deliver($events, BatchId::random(), $this->mode);
        } catch (MiraError $error) {
            if ($error->errorCode !== 'payload_too_large' || count($events) === 1) {
                $this->reporter->report($error);

                return;
            }

            $half = intdiv(count($events), 2);
            $this->deliverSplitting(array_slice($events, 0, $half));
            $this->deliverSplitting(array_slice($events, $half));
        }
    }

    private static function now(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
