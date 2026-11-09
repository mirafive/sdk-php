<?php

declare(strict_types=1);

namespace MiraFive;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use MiraFive\Http\CurlTransport;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use MiraFive\Internal\Batch;
use MiraFive\Internal\Breaker;
use MiraFive\Internal\Context;
use MiraFive\Internal\Delivery;
use MiraFive\Internal\Env;
use MiraFive\Internal\Pipeline;
use MiraFive\Internal\Properties;
use MiraFive\Internal\Reporter;
use MiraFive\Internal\Store;
use MiraFive\Internal\Text;
use MiraFive\Internal\Time;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * The MIRA FIVE server client. Events wait in memory and leave in batches when 100 are waiting, on
 * `flush()`, and when the request ends. Sessions, identity and attribution happen on the MIRA
 * server; this client only delivers events, reliably and at most once per `event_id`.
 *
 * The event methods never throw: invalid input and delivery problems go to `onError`, else the
 * logger, else `error_log()`, as a `MiraError` with the HTTP status and the affected event ids.
 *
 * @phpstan-type ContextInput array{ip?: string, userAgent?: string, page?: array{url?: string, referrer?: string, title?: string}, app?: array{name?: string, version?: string, build?: string}, locale?: string, timezone?: string, location?: array{latitude: float|int, longitude: float|int}}
 * @phpstan-type ConsentInput Consent|'not_applicable'|array{analytics: bool|'granted'|'denied'|'unknown', marketing?: bool|'granted'|'denied'|'unknown'}
 * @phpstan-type SubscriptionInput array{id: string, interval?: 'day'|'week'|'month'|'year', intervalCount?: int, periodStart?: DateTimeInterface|int|string, periodEnd?: DateTimeInterface|int|string}
 */
final class Mira
{
    public const string VERSION = '2.1.0';

    public const string LIBRARY = 'mirafive/sdk-php';

    public const string DEFAULT_HOST = 'https://events.mirafive.io';

    public const string SECRET_KEY_PREFIX = 'mira_sk_';

    public const string PUBLIC_KEY_PREFIX = 'mira_pk_';

    /** The ingest takes at most 100 events per request. */
    public const int MAX_BATCH = 100;

    public const array EXTERNAL_ID_TYPES = ['stripe_customer', 'revenuecat_app_user', 'shopify_customer', 'shopware_customer'];

    public const array REVENUE_KINDS = ['charge', 'subscription_payment', 'refund', 'dispute', 'dispute_won'];

    public const array BILLING_INTERVALS = ['day', 'week', 'month', 'year'];

    public const array TOUCHPOINT_KINDS = ['survey', 'offline', 'code', 'custom'];

    public const array TOUCHPOINT_CHANNELS = [
        'paid_search', 'paid_social', 'paid_other', 'organic_search', 'organic_social', 'ai_assistant',
        'email', 'affiliate', 'display', 'video', 'qr', 'app_store', 'referral', 'direct',
    ];

    private const int MAX_ID = 200;

    /** The ingest origin, without a trailing slash. */
    public readonly string $host;

    /** Whether events leave this process: enabled, and with a secret key (or a hand-off). */
    public readonly bool $sends;

    private readonly Pipeline $pipeline;

    private readonly Reporter $reporter;

    private readonly ?ClockInterface $clock;

    /** @var array{userId?: string, anonymousId?: string, consent?: mixed, context?: array<string, mixed>} */
    private array $defaults = [];

    /**
     * @param  string|null  $secretKey  the project's secret key (`mira_sk_…`); null reads MIRAFIVE_SECRET_KEY. Never ship it to a browser.
     * @param  string|null  $host  the ingest origin; null reads MIRAFIVE_HOST, else https://events.mirafive.io
     * @param  int  $flushAt  send as soon as this many events are waiting, 1 to 100
     * @param  int  $timeoutMs  per request; requests usually run inside your web request, so keep it short
     * @param  int  $flushDeadlineMs  what one flush may spend on attempts and waits together
     * @param  int  $maxRetries  retries per batch on network errors, timeouts, 408, 429 and 5xx
     * @param  int  $maxRetryAfterMs  a longer `Retry-After` is not waited for: the batch is reported instead
     * @param  (callable(MiraError): void)|null  $onError  receives every problem; the event methods never throw
     * @param  LoggerInterface|null  $logger  used when there is no onError
     * @param  CacheInterface|null  $cache  shares the delivery breaker between PHP processes
     * @param  Transport|null  $transport  default: cURL, else PHP streams; Http\Psr18Transport wraps your PSR-18 client
     * @param  bool  $enabled  false: input is still checked, but nothing leaves the process and no key is needed
     * @param  bool  $flushOnShutdown  false when a framework flushes after the response (the Laravel and Symfony packages do)
     * @param  (Closure(string): void)|null  $handOff  receives each batch payload instead of sending it; a queue worker sends it with deliverPrepared()
     * @param  ClockInterface|null  $clock  the default event time and `sent_at` (tests, simulations)
     * @param  (Closure(int): void)|null  $sleep  waits between retries, in milliseconds (tests)
     * @param  string|null  $stream  the batch stream id; default random per client (tests)
     */
    public function __construct(
        ?string $secretKey = null,
        ?string $host = null,
        int $flushAt = self::MAX_BATCH,
        int $timeoutMs = 3_000,
        int $connectTimeoutMs = 1_000,
        int $flushDeadlineMs = 3_000,
        int $maxRetries = 2,
        int $maxRetryAfterMs = 3_000,
        ?callable $onError = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        ?Transport $transport = null,
        bool $enabled = true,
        bool $flushOnShutdown = true,
        ?Closure $handOff = null,
        ?ClockInterface $clock = null,
        ?Closure $sleep = null,
        ?string $stream = null,
    ) {
        $key = trim($secretKey ?? Env::read('MIRAFIVE_SECRET_KEY') ?? '');
        $this->host = self::origin($host ?? Env::read('MIRAFIVE_HOST') ?? self::DEFAULT_HOST);
        $this->clock = $clock;
        $this->reporter = new Reporter($onError === null ? null : $onError(...), $logger);
        // A public key is refused like a missing one, as in every MIRA FIVE server SDK: it belongs in
        // browsers, and the ingest would reject revenue and most touchpoints sent with it.
        $publicKey = str_starts_with($key, self::PUBLIC_KEY_PREFIX);
        $usable = $key !== '' && ! $publicKey;
        $this->sends = $enabled && ($usable || $handOff !== null);

        if ($enabled && $publicKey) {
            $this->reporter->report(new MiraError(
                'this is a public key (mira_pk_…): server code needs the project\'s secret key (mira_sk_…); '
                .'public keys belong in @mirafive/web. Events are dropped.',
            ));
        } elseif ($enabled && $key === '' && $handOff === null) {
            $this->reporter->report(new MiraError('no secret key: pass secretKey or set MIRAFIVE_SECRET_KEY; events will be dropped'));
        }

        $delivery = $enabled && $usable ? new Delivery(
            $transport ?? (extension_loaded('curl') ? new CurlTransport : new StreamTransport),
            $this->host.'/v1/batch',
            $key,
            max(1, $timeoutMs),
            max(1, $connectTimeoutMs),
            max(0, $maxRetries),
            max(0, $maxRetryAfterMs),
            $sleep ?? static function (int $ms): void {
                usleep($ms * 1000);
            },
            // Static, without $this: a cycle back to the client would delay its destructor's flush.
            static fn (): string => Time::iso($clock?->now() ?? new DateTimeImmutable),
        ) : null;

        $this->pipeline = new Pipeline(
            $delivery,
            $this->reporter,
            new Breaker(new Store($cache, $this->host, $key)),
            $stream ?? Uuid::v7(),
            min(max(1, $flushAt), self::MAX_BATCH),
            max(1, $flushDeadlineMs),
            $flushOnShutdown,
            $enabled ? $handOff : null,
        );
    }

    /**
     * A stable event id from your own key, such as `order:123:paid`. The same key always gives the
     * same UUID, in PHP, Node and every other MIRA FIVE SDK, so a retried job, a backfill run twice,
     * or the browser and the server reporting one conversion all count once.
     */
    public static function eventIdFor(string $key): string
    {
        return Uuid::forKey($key);
    }

    /**
     * A client that fills in these values for every event it sends; the call's own values win, and
     * `context` is merged. It shares the buffer with this client. Use it per request or per user.
     *
     * @param  ConsentInput|null  $consent
     * @param  ContextInput|array<string, mixed>|null  $context
     */
    public function with(
        ?string $userId = null,
        ?string $anonymousId = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): self {
        $scope = clone $this;
        $scope->defaults = array_filter([
            'userId' => $userId ?? $this->defaults['userId'] ?? null,
            'anonymousId' => $anonymousId ?? $this->defaults['anonymousId'] ?? null,
            'consent' => $consent ?? $this->defaults['consent'] ?? null,
            'context' => $context === null ? $this->defaults['context'] ?? null : [...$this->defaults['context'] ?? [], ...$context],
        ], static fn (mixed $value): bool => $value !== null);

        return $scope;
    }

    /**
     * A custom event. Names starting with `$` are reserved for MIRA.
     *
     * @param  array<array-key, mixed>|object  $properties  nested values are flattened to dot keys
     * @param  ConsentInput|null  $consent  default `not_applicable`
     * @param  ContextInput|null  $context
     */
    public function track(
        string $event,
        ?string $userId = null,
        ?string $anonymousId = null,
        array|object $properties = [],
        ?string $eventId = null,
        DateTimeInterface|int|string|null $timestamp = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): void {
        $this->safely(function () use ($event, $userId, $anonymousId, $properties, $eventId, $timestamp, $consent, $context): void {
            if (trim($event) === '') {
                throw new MiraError('track: event is required');
            }

            if (str_starts_with($event, '$')) {
                throw new MiraError("track: \"{$event}\" is reserved (names starting with \$)");
            }

            $this->push(['type' => 'track', 'event' => $event, 'properties' => Properties::clean($properties)], $userId, $anonymousId, $eventId, $timestamp, $consent, $context);
        });
    }

    /**
     * Who the user is, with traits. PII traits (email, name, phone, …) are encrypted at rest by MIRA.
     *
     * @param  array<array-key, mixed>|object  $traits
     * @param  array<string, string>  $externalIds  the person's ids in other systems, so connectors link their money to this user: stripe_customer, revenuecat_app_user, shopify_customer, shopware_customer
     * @param  ConsentInput|null  $consent
     * @param  ContextInput|null  $context
     */
    public function identify(
        string $userId,
        array|object $traits = [],
        array $externalIds = [],
        ?string $anonymousId = null,
        ?string $eventId = null,
        DateTimeInterface|int|string|null $timestamp = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): void {
        $this->safely(function () use ($userId, $traits, $externalIds, $anonymousId, $eventId, $timestamp, $consent, $context): void {
            if (trim($userId) === '') {
                throw new MiraError('identify: userId is required');
            }

            $event = ['type' => 'identify', 'event' => '$identify', 'traits' => Properties::clean($traits)];
            $ids = $this->externalIds($externalIds);

            if ($ids !== []) {
                $event['external_ids'] = (object) $ids;
            }

            $this->push($event, $userId, $anonymousId, $eventId, $timestamp, $consent, $context);
        });
    }

    /**
     * The company or account a user belongs to (B2B), with its traits: name, size, plan.
     *
     * @param  array<array-key, mixed>|object  $traits
     * @param  ConsentInput|null  $consent
     * @param  ContextInput|null  $context
     */
    public function group(
        string $groupId,
        ?string $userId = null,
        array|object $traits = [],
        ?string $anonymousId = null,
        ?string $eventId = null,
        DateTimeInterface|int|string|null $timestamp = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): void {
        $this->safely(function () use ($groupId, $userId, $traits, $anonymousId, $eventId, $timestamp, $consent, $context): void {
            if (trim($groupId) === '') {
                throw new MiraError('group: groupId is required');
            }

            $this->push(['type' => 'group', 'event' => '$group', 'group_id' => $groupId, 'traits' => Properties::clean($traits)], $userId, $anonymousId, $eventId, $timestamp, $consent, $context);
        });
    }

    /**
     * Money, as `$revenue`: one entry in the project's revenue ledger, keyed by `entryId`. Only secret
     * keys may send revenue. Pass `entryId` when the same money also arrives through a connector
     * (Stripe, RevenueCat), so it is counted once.
     *
     * @param  int|float  $amount  what the customer paid, in integer minor units: cents for EUR and USD, yen for JPY; 1999 is 19.99 EUR. Negative for refunds.
     * @param  string  $currency  ISO 4217, for example EUR
     * @param  string|null  $entryId  the provider object this money belongs to (`stripe:in_123`, `crm:case-81`)
     * @param  'charge'|'subscription_payment'|'refund'|'dispute'|'dispute_won'|null  $entryKind  default: subscription_payment with a subscription, refund for a negative amount, else charge
     * @param  int|float|null  $fee  deprecated since 2.1.0: not sent, MIRA FIVE counts what the customer paid; removed in 3.0.0
     * @param  int|float|null  $tax  deprecated since 2.1.0: not sent, MIRA FIVE counts what the customer paid; removed in 3.0.0
     * @param  string|null  $refundOf  for a refund or dispute: the entryId of the money it takes back
     * @param  string|null  $product  the product or plan, for example premium_monthly
     * @param  SubscriptionInput|null  $subscription  the subscription this payment renews, and the period it pays for
     * @param  array<array-key, mixed>|object  $properties
     * @param  ConsentInput|null  $consent
     * @param  ContextInput|null  $context
     */
    public function revenue(
        int|float $amount,
        string $currency,
        ?string $userId = null,
        ?string $anonymousId = null,
        ?string $entryId = null,
        ?string $entryKind = null,
        int|float|null $fee = null,
        int|float|null $tax = null,
        ?string $refundOf = null,
        ?string $product = null,
        ?array $subscription = null,
        array|object $properties = [],
        ?string $eventId = null,
        DateTimeInterface|int|string|null $timestamp = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): void {
        $this->safely(function () use ($amount, $currency, $userId, $anonymousId, $entryId, $entryKind, $refundOf, $product, $subscription, $properties, $eventId, $timestamp, $consent, $context): void {
            $revenue = ['amount' => self::minor('amount', $amount)];

            if (preg_match('/^[A-Za-z]{3}$/', $currency) !== 1) {
                throw new MiraError('revenue: currency must be an ISO 4217 code');
            }

            $revenue['currency'] = strtoupper($currency);

            if ($entryKind !== null && ! in_array($entryKind, self::REVENUE_KINDS, true)) {
                throw new MiraError('revenue: entryKind must be one of '.implode(', ', self::REVENUE_KINDS));
            }

            $optional = [
                'entry_id' => $entryId,
                'kind' => $entryKind,
                'refund_of' => $refundOf,
                'product' => $product,
                'subscription' => $subscription === null ? null : $this->subscription($subscription),
            ];

            foreach ($optional as $name => $value) {
                if ($value !== null && $value !== '') {
                    $revenue[$name] = $value;
                }
            }

            $this->push(['type' => 'track', 'event' => '$revenue', 'properties' => Properties::clean($properties), 'revenue' => $revenue], $userId, $anonymousId, $eventId, $timestamp, $consent, $context);
        });
    }

    /**
     * A touchpoint that is not a visit, as `$touchpoint`: the answer to "How did you hear about us?",
     * a trade fair contact, a redeemed code. The attribution models credit it like a visit. It needs
     * a userId or an anonymousId, and a channel or a source; without a channel, source, medium and
     * campaign decide it like the UTMs of a link. With `eventIdFor('survey:'.$responseId)` a re-sent
     * answer counts once.
     *
     * @param  'survey'|'offline'|'code'|'custom'  $kind
     * @param  string|null  $channel  one of MIRA's channels (see TOUCHPOINT_CHANNELS)
     * @param  string|null  $source  where it came from: podcast, instagram, trade_fair_2026, a partner
     * @param  string|null  $content  the ad or creative, as utm_content names it for a visit
     * @param  string|null  $code  the discount or partner code
     * @param  string|null  $answer  the survey answer as the person gave it; it passes MIRA's PII guard
     * @param  string|null  $externalRef  the record in your system, such as a CRM contact (hubspot:123)
     * @param  array<array-key, mixed>|object  $properties
     * @param  ConsentInput|null  $consent
     * @param  ContextInput|null  $context
     */
    public function touchpoint(
        string $kind,
        ?string $userId = null,
        ?string $anonymousId = null,
        ?string $channel = null,
        ?string $source = null,
        ?string $medium = null,
        ?string $campaign = null,
        ?string $content = null,
        ?string $campaignId = null,
        ?string $adGroupId = null,
        ?string $adId = null,
        ?string $code = null,
        ?string $answer = null,
        ?string $externalRef = null,
        array|object $properties = [],
        ?string $eventId = null,
        DateTimeInterface|int|string|null $timestamp = null,
        Consent|string|array|null $consent = null,
        ?array $context = null,
    ): void {
        $this->safely(function () use ($kind, $userId, $anonymousId, $channel, $source, $medium, $campaign, $content, $campaignId, $adGroupId, $adId, $code, $answer, $externalRef, $properties, $eventId, $timestamp, $consent, $context): void {
            if (! in_array($kind, self::TOUCHPOINT_KINDS, true)) {
                throw new MiraError('touchpoint: kind must be one of '.implode(', ', self::TOUCHPOINT_KINDS));
            }

            if ($channel !== null && $channel !== '' && ! in_array($channel, self::TOUCHPOINT_CHANNELS, true)) {
                throw new MiraError('touchpoint: channel must be one of '.implode(', ', self::TOUCHPOINT_CHANNELS));
            }

            if (($channel ?? '') === '' && trim($source ?? '') === '') {
                throw new MiraError('touchpoint: a channel or a source is required');
            }

            if (($userId ?? $this->defaults['userId'] ?? '') === '' && ($anonymousId ?? $this->defaults['anonymousId'] ?? '') === '') {
                throw new MiraError('touchpoint: a userId or an anonymousId is required');
            }

            $cleaned = Properties::clean($properties);
            $fields = [
                'kind' => $kind, 'channel' => $channel, 'source' => $source, 'medium' => $medium,
                'campaign' => $campaign, 'content' => $content, 'campaign_id' => $campaignId,
                'ad_group_id' => $adGroupId, 'ad_id' => $adId, 'code' => $code, 'answer' => $answer,
                'external_ref' => $externalRef,
            ];

            foreach ($fields as $name => $value) {
                if ($value !== null && $value !== '') {
                    $cleaned->{$name} = $value;
                }
            }

            $this->push(['type' => 'track', 'event' => '$touchpoint', 'properties' => $cleaned], $userId, $anonymousId, $eventId, $timestamp, $consent, $context);
        });
    }

    /** Sends everything that is waiting, within the flush deadline. Never throws; see onError. */
    public function flush(): void
    {
        $this->pipeline->flush();
    }

    /** Flushes, and refuses new events. Call it before a long-running process exits. */
    public function shutdown(): void
    {
        $this->pipeline->shutdown();
    }

    /**
     * Sends a batch payload that a `handOff` received, in a queue worker, with this client's key and
     * the usual retries (no flush deadline). Events the ingest rejects are reported; the batch is not.
     *
     * @throws MiraError when the batch was not delivered: rethrow a `retryable` one so the queue tries again later
     */
    public function deliverPrepared(string $payload): BatchResult
    {
        return $this->pipeline->send(Batch::parse($payload));
    }

    /**
     * Proves that the secret key and host work, without sending an event (`GET /v1/check`): nothing
     * is stored or counted. Returns the key's kind, `secret` or `public`.
     *
     * @return 'public'|'secret'
     *
     * @throws MiraError when the host cannot be reached, is no MIRA FIVE ingest, or refuses the key
     */
    public function checkConnection(): string
    {
        return $this->pipeline->check();
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array<array-key, mixed>|null  $context
     *
     * @throws MiraError
     */
    private function push(
        array $event,
        ?string $userId,
        ?string $anonymousId,
        ?string $eventId,
        DateTimeInterface|int|string|null $timestamp,
        mixed $consent,
        ?array $context,
    ): void {
        $name = is_string($event['event'] ?? null) ? $event['event'] : 'event';

        if ($this->pipeline->closed()) {
            throw new MiraError("{$name}: the client was shut down");
        }

        if ($eventId !== null && ! Uuid::valid($eventId)) {
            throw new MiraError("{$name}: eventId must be a UUID (see Mira::eventIdFor())");
        }

        $id = $eventId === null ? Uuid::v7() : strtolower($eventId);
        $userId = $userId ?? $this->defaults['userId'] ?? null;
        $anonymousId = $anonymousId ?? $this->defaults['anonymousId'] ?? null;
        $given = $context === null ? $this->defaults['context'] ?? [] : [...$this->defaults['context'] ?? [], ...$context];

        $event['event_id'] = $id;
        $event['timestamp'] = $timestamp === null ? Time::iso($this->now()) : Time::from($timestamp);
        $event['context'] = Context::build($given, $name, $this->reporter);
        $event['consent'] = Consent::from($consent ?? $this->defaults['consent'] ?? null)->toWire();

        if ($userId !== null && $userId !== '') {
            $event['user_id'] = $userId;
        }

        if ($anonymousId !== null && $anonymousId !== '') {
            $event['anonymous_id'] = $anonymousId;
        }

        $this->pipeline->push($event, $id);
    }

    /**
     * Known types with usable values only. An id is never cut short: a truncated id would link the
     * money to the wrong person, so an overlong one is dropped.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<string, string>
     */
    private function externalIds(array $input): array
    {
        $ids = [];

        foreach ($input as $type => $value) {
            if (! in_array($type, self::EXTERNAL_ID_TYPES, true)) {
                $this->reporter->report(new MiraError("identify: unknown external id type \"{$type}\" dropped (known: ".implode(', ', self::EXTERNAL_ID_TYPES).')'));
            } elseif (is_string($value) && trim($value) !== '' && Text::cut($value, self::MAX_ID) === $value) {
                $ids[$type] = $value;
            }
        }

        return $ids;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, string|int>
     *
     * @throws MiraError
     */
    private function subscription(array $input): array
    {
        $id = $input['id'] ?? null;

        if (! is_string($id) || trim($id) === '') {
            throw new MiraError('revenue: subscription.id is required');
        }

        $subscription = ['id' => $id];
        $interval = $input['interval'] ?? null;

        if ($interval !== null) {
            if (! in_array($interval, self::BILLING_INTERVALS, true)) {
                throw new MiraError('revenue: subscription.interval must be one of '.implode(', ', self::BILLING_INTERVALS));
            }

            $subscription['interval'] = $interval;
        }

        $count = $input['intervalCount'] ?? null;

        if ($count !== null) {
            if (! is_int($count) || $count < 1 || $count > 36) {
                throw new MiraError('revenue: subscription.intervalCount must be an integer from 1 to 36');
            }

            $subscription['interval_count'] = $count;
        }

        foreach (['periodStart' => 'period_start', 'periodEnd' => 'period_end'] as $field => $wire) {
            $value = $input[$field] ?? null;

            if ($value !== null) {
                if (! $value instanceof DateTimeInterface && ! is_int($value) && ! is_string($value)) {
                    throw new MiraError("revenue: subscription.{$field} must be a DateTimeInterface, epoch milliseconds or an ISO string");
                }

                $subscription[$wire] = Time::from($value);
            }
        }

        return $subscription;
    }

    /**
     * Minor units as an integer. A float is fine when it holds a whole number (1999.0).
     *
     * @throws MiraError
     */
    private static function minor(string $field, int|float $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_finite($value) && floor($value) === $value && abs($value) <= 2 ** 53) {
            return (int) $value;
        }

        throw new MiraError("revenue: {$field} must be an integer in minor units (1999 for 19.99)");
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable;
    }

    private static function origin(string $host): string
    {
        $host = rtrim(trim($host), '/');

        if (preg_match('#^https?://[^/\s]+$#i', $host) !== 1) {
            throw new InvalidArgumentException("MIRA FIVE host must be an origin such as https://events.mirafive.io, got \"{$host}\"");
        }

        return $host;
    }

    /**
     * @param  Closure(): void  $action
     */
    private function safely(Closure $action): void
    {
        try {
            $action();
        } catch (MiraError $error) {
            $this->reporter->report($error);
        } catch (Throwable $exception) {
            $this->reporter->report(new MiraError('unexpected error: '.$exception->getMessage(), previous: $exception));
        }
    }
}
