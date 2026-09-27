# MIRA FIVE for PHP

Privacy-first analytics and feature flags for PHP servers: send events from your backend and evaluate flags in-process, hosted in the EU.

## Install

```bash
composer require mirafive/sdk-php
```

PHP 8.3 or newer with `ext-json`. `ext-curl` is used when present, otherwise PHP streams; you can also send through your own PSR-18 client. There are no required Composer dependencies.

Using a framework? Take [`mirafive/sdk-laravel`](https://packagist.org/packages/mirafive/sdk-laravel) or [`mirafive/sdk-symfony`](https://packagist.org/packages/mirafive/sdk-symfony): they wire this SDK into the container and flush after the response.

## Quickstart

Create a **server source** in MIRA FIVE and put its secret key in the environment:

```bash
MIRAFIVE_SECRET_KEY=mf_ab12cd34_…
```

```php
use MiraFive\Mira;

$mira = new Mira(); // reads MIRAFIVE_SECRET_KEY (and MIRAFIVE_HOST, if set)

$mira->track('signup', userId: 'u_42', properties: ['plan' => 'pro']);
$mira->identify('u_42', ['plan' => 'pro', 'company' => 'Example GmbH']);

// Sent when the request ends. To send now:
$mira->flush();
```

For events that must be recorded exactly once, such as a payment webhook that may be delivered twice, send them immediately with an idempotency key:

```php
$receipt = $mira->send([
    ['name' => 'order completed', 'userId' => 'u_42', 'properties' => ['revenue' => 129, 'currency' => 'EUR']],
], idempotencyKey: 'order-981');
```

The same key always maps to the same batch, so a repeat is stored once.

## Consent & privacy

MIRA FIVE has two collection modes:

- **Full** (`Mode::Full`, the default) may carry `userId`, `anonymousId` and `sessionId`. Use it for people who consented, or where you already hold another lawful basis for the processing.
- **Consentless** (`Mode::Consentless`) carries no identifiers at all. Passing one throws an `InvalidArgumentException`, so a misconfiguration shows up on the first call instead of as refused batches.

```php
use MiraFive\Mode;

$mira = new Mira(mode: Mode::Consentless);
$mira->track('invoice paid', properties: ['revenue' => 99, 'currency' => 'EUR']);
```

Rules that hold in both modes:

- **Never put personal data in event names or properties.** No e-mail addresses, names, phone numbers or free text a person typed. Event names are labels such as `signup`, not `signup max@example.com`.
- **`userId` is pseudonymous.** Pass your own internal id (`u_42`, a UUID), never an e-mail address.
- **The secret key stays on the server.** It never goes into HTML, JavaScript or a mobile app. Browsers use the public website key with the browser SDK.

## API reference

### `MiraFive\Mira`

```php
new Mira(
    key: null,              // string|false|null. null reads MIRAFIVE_SECRET_KEY; getenv() results are accepted as-is
    host: null,             // null reads MIRAFIVE_HOST, else Mira::DEFAULT_HOST (https://events.mirafive.io). A scheme is required
    mode: Mode::Full,
    flushAt: 100,           // send once this many events are buffered (1–1000)
    timeoutMs: 5_000,       // per attempt
    maxRetries: 2,          // for 408, 429, 5xx, timeouts and network errors
    maxRetryAfterMs: 3_000, // a Retry-After longer than this ends the retries instead of stalling your request
    transport: null,        // MiraFive\Http\Transport; default CurlTransport, else StreamTransport
    onError: null,          // callable(MiraError): void, receives every delivery failure
    logger: null,           // Psr\Log\LoggerInterface, used when there is no onError
    cache: null,            // Psr\SimpleCache\CacheInterface, shares the flag document between processes
    enabled: true,          // false: nothing leaves the process and no key is needed; input is still checked
    flushOnShutdown: true,  // false: no shutdown function (your framework flushes on terminate)
    flagsRefreshSeconds: 30,// refresh interval of flags()
    handOff: null,          // Closure(string $body, string $batchId): void, receives buffered batches instead of sending them
);
```

| Member | Behaviour |
|---|---|
| `track(string $name, ?string $userId = null, ?string $anonymousId = null, ?string $sessionId = null, array $properties = [], DateTimeInterface\|int\|null $time = null, ?array $page = null): void` | Buffers one event. `time` is a `DateTimeInterface` or epoch milliseconds; it defaults to now. `page` takes `url`, `title`, `referrer`. |
| `identify(string $userId, array $traits = [], ?string $anonymousId = null, DateTimeInterface\|int\|null $time = null): void` | Buffers `$identify`: the person's traits, and a link from the browser's anonymous id when given. Full mode only. |
| `send(array $events, ?string $idempotencyKey = null): Receipt` | Sends 1–1000 events now as one batch. Each event is an array with `name` and optionally `userId`, `anonymousId`, `sessionId`, `properties`, `time`, `page`, `id`. Throws `MiraError`. |
| `flush(): void` | Sends the buffer, or hands it to `handOff`. Never throws; failures go to `onError`, the logger, or `error_log()`. |
| `deliverPrepared(string $body): Receipt` | Sends a batch a `handOff` received, with the usual retries, under this client's key. Throws `MiraError`. |
| `flags(): Flags\MiraFlags` | The flags of this source, sharing key, host, transport and cache. |

The buffer is also sent when `flushAt` is reached, when the `Mira` object is destroyed, and once in a shutdown function at the end of the request (unless `flushOnShutdown: false`).

**Defaults.** `flushAt` 100 events, `timeoutMs` 5,000 per attempt, `maxRetries` 2, `maxRetryAfterMs` 3,000. They are lower than the Node server SDK's (10 s timeout, 3 retries) because delivery usually runs inside a PHP web request. For flags: `refreshSeconds` 30, a 1,500 ms document fetch and a 500 ms segment lookup.

**Delivery.** Batches go to `POST {host}/v1/batch` as JSON with the secret key as a bearer token, at most 1000 events and 1 MiB each (larger buffers are split). Retries use full-jitter exponential backoff (100 ms base, 1 s cap), honour `Retry-After`, and resend the byte-identical body under the same batch id, so a retry is never counted twice.

**Input checks.** Input the collector would refuse throws an `InvalidArgumentException` immediately, because one bad event would otherwise cost every event in its batch: names of 1–128 characters without surrounding whitespace, `$` names other than the reserved ones (`$pageview`, `$autocapture`, `$identify`, `$search`, `$install_check`, `$exposure`), blank or overlong ids, properties that are a list, nest deeper than 5 levels, carry more than 64 values or encode to more than 32 KB. Page fields that are too long are shortened instead.

### Queues, frameworks and tests

- **Deliver from a queue.** With `handOff`, every buffered flush (explicit, at `flushAt`, on shutdown) passes the encoded batch to your closure instead of sending it. Put the body on a queue; the worker calls `$mira->deliverPrepared($body)` on its own `Mira`, so the key never travels in the message. The body is final: retries resend it byte for byte under its batch id, so a job that runs twice is stored once. `send()` ignores `handOff` and always sends immediately.
- **Flush on terminate.** Frameworks pass `flushOnShutdown: false` and call `flush()` after the response.
- **Local and test environments.** `enabled: false` sends nothing and needs no key, but refuses the same input as production. `track()` keeps nothing, `send()` and `deliverPrepared()` return a local receipt with every event accepted, and flags answer their fallbacks.

### `MiraFive\Receipt`

`batch` (string), `accepted` (int), `dropped` (int), `reason` (`bot`, `install_check`, `ingestion_paused`, `allowance_exhausted` or null). `dropped > 0` with a reason means nothing was kept; the answer is still final.

### `MiraFive\MiraError`

Extends `RuntimeException`.

| Property | Meaning |
|---|---|
| `errorCode` | The protocol code: `validation_failed`, `unauthorized`, `rate_limited`, `payload_too_large`, `sink_unavailable`, …, plus `network_error`, `timeout` and `unexpected` |
| `status` | HTTP status, or null when no answer came. `getCode()` returns it too (0 without one) |
| `retryable` | Whether trying again later can succeed |
| `retryAfterMs` | From `Retry-After`, when sent |
| `errors` | For `validation_failed`: up to 10 `['path' => …, 'message' => …]` |

### Transports

`MiraFive\Http\CurlTransport` (default, reuses one connection per request), `MiraFive\Http\StreamTransport` (no extensions needed) and `MiraFive\Http\Psr18Transport`:

```php
use MiraFive\Http\Psr18Transport;

$mira = new Mira(transport: new Psr18Transport($client, $requestFactory, $streamFactory));
```

A PSR-18 client applies its own timeout. Keep it short: delivery runs inside your request.

## Flags

```php
$flags = $mira->flags(); // or new MiraFive\Flags\MiraFlags(key: …, cache: $psr16Cache)

$user = $flags->for(
    userId: 'u_42',
    properties: ['plan' => 'pro'],
    consent: ['experiments' => true, 'targeting' => false], // optional: the banner's answer
    optedOut: false,                                        // true when the request carries Sec-GPC: 1 or DNT: 1
);

$user->enabled('new-checkout');            // bool
$user->variant('pricing-test');            // ?string
$user->config('limits', ['max' => 3]);     // the variant's value, objects as arrays
$user->evaluate('pricing-test');           // Evaluation: variant, reason, rule, errorCode, value
```

`for()` takes `userId` (the unit of flags assigned by signed-in person), `anonymousId` (MIRA FIVE's anonymous id from the browser SDK, the unit of flags assigned by browser) and `properties` (facts your rules test; they stay in memory and are never sent). Reads are synchronous and never throw: without a document, or for an unknown key, they answer your fallback.

**Consent and opt-out** (FLAGS.md §5.1). Leave a scope out of `consent` when your own lawful basis applies; set it to `false` when the person declined:

| Input | Effect |
|---|---|
| `consent: ['experiments' => false]` | The anonymous id is not used, so flags assigned by browser answer their default. Every experiment answers its default (`NOT_ALLOWED`) and nobody is counted |
| `consent: ['targeting' => false]` | No segment lookup; segment conditions are false (`NOT_ALLOWED`) |
| `optedOut: true` | No unit at all, no segment lookup, no exposure, whatever `consent` says. Fixed values and property rules still apply |

**The document.** `MiraFlags` fetches `GET {host}/v1/flags` on first use and again on a read once it is older than `refreshSeconds` (default 30, at least 10), revalidating with `If-None-Match`. PHP-FPM starts every request with an empty process, so pass a PSR-16 cache to share the document (and failed fetches) between requests; otherwise each request fetches it once.

```php
new MiraFlags(
    key: null,              // null reads MIRAFIVE_SECRET_KEY
    host: null,
    refreshSeconds: 30,
    timeoutMs: 1_500,       // the document fetch
    lookupTimeoutMs: 500,   // the segment lookup
    cache: null,            // Psr\SimpleCache\CacheInterface
    document: null,         // a snapshot (JSON or array), used while none was fetched and while younger than 7 days
    enabled: true,          // false: never fetches or looks up, so reads answer their fallbacks
    mira: null,             // sends exposures; defaults to a client on the same key
    transport: null,
    onError: null,
    logger: null,
);
```

**Segments.** When a flag tests segments, `for()` asks `POST {host}/v1/flags/segments` about the unit once per minute, with a short timeout. If the lookup fails, segment conditions count as false for a minute and `evaluate()` reports `MEMBERSHIP_UNAVAILABLE`.

**Experiments.** `enabled`, `variant` and `config` send one `$exposure` per person, experiment and variant for experiments counted on your server. `evaluate()` never counts anyone. Experiments counted in the browser answer their default on the server (`NOT_ALLOWED`).

**Bootstrap.** Hand the server's answers to the browser SDK so the first paint shows the right variant:

```php
foreach (MiraFive\Flags\MiraFlags::BOOTSTRAP_HEADERS as $name => $value) {
    header("{$name}: {$value}"); // Cache-Control: private, no-store
}

echo $user->bootstrap(); // <script type="application/json" id="mirafive-flags">…</script>
```

Only flags your website reads are included, and every `<`, `>`, `&`, U+2028 and U+2029 is escaped, so no value can end the script. Never let a shared cache store such a page.

**Reasons** (`MiraFive\Flags\Reason`): `STATIC`, `TARGETING_MATCH`, `SPLIT`, `DEFAULT`, `DISABLED`, `ERROR`. **Error codes** (`MiraFive\Flags\ErrorCode`): `UNSUPPORTED` (the flag needs a newer SDK), `NOT_READY`, `FLAG_NOT_FOUND`, `MEMBERSHIP_UNAVAILABLE`, `NOT_ALLOWED`.

## Troubleshooting

- **Nothing arrives.** Pass `onError` (or a logger) and look at `errorCode`. Without either, failures go to `error_log()`. Then check the key and host with an install check (below).
- **`unauthorized`.** The key is missing, wrong or revoked. `MIRAFIVE_SECRET_KEY` must hold the secret key of a *server* source.
- **`website_key_as_bearer`.** You passed the public website key. Server code needs the secret key.
- **`InvalidArgumentException: A consentless client may not send userId`.** The client is in `Mode::Consentless`; drop the identifiers or use `Mode::Full` where you have consent.
- **Events arrive only at the end of a long job, or never, in a worker.** Octane, RoadRunner, Swoole and queue workers run for many requests, so the shutdown flush only fires when the worker stops. Call `$mira->flush()` after each request or job (the Laravel and Symfony packages do).
- **Slow responses when MIRA FIVE is unreachable.** Each flush tries up to `maxRetries + 1` times, `timeoutMs` each. Lower both, or flush after the response has been sent (`fastcgi_finish_request()`).
- **Flags always answer the fallback.** Look at `$flags->status()` and `$user->evaluate($key)->errorCode`: `NOT_READY` means no document yet (see `onError`), `FLAG_NOT_FOUND` that the flag is not served to this source.

## For AI agents

(a) A prompt for an agent adding MIRA FIVE to a plain PHP application:

```text
Add MIRA FIVE server-side analytics to this PHP application with the Composer package mirafive/sdk-php.

1. Run `composer require mirafive/sdk-php`. It needs PHP 8.3+ and has no other Composer dependencies.
2. Read the secret key from the environment variable MIRAFIVE_SECRET_KEY. Never hard-code it and never
   print it into HTML or JavaScript. Add MIRAFIVE_SECRET_KEY= to .env.example if the project has one.
3. Create one shared MiraFive\Mira instance at bootstrap (e.g. `$mira = new \MiraFive\Mira();`) and reuse it.
4. Track the few business events that matter (e.g. signup, order completed) with
   `$mira->track('signup', userId: $user->id, properties: ['plan' => $plan]);`.
   Use the app's internal user id, never an e-mail address. No personal data in names or properties.
   After login or signup call `$mira->identify($user->id, ['plan' => $plan]);`.
5. For webhooks that can be delivered twice, use
   `$mira->send([['name' => 'order completed', 'userId' => $id, 'properties' => ['revenue' => $amount, 'currency' => 'EUR']]], idempotencyKey: $orderId);`.
6. The buffer flushes when the request ends. In long-running workers (queues, Octane, RoadRunner)
   call `$mira->flush()` after each job or request.
7. Pass `onError: fn (\MiraFive\MiraError $e) => <the app's logger>` so delivery failures are visible.
8. Verify: `$receipt = $mira->send([['name' => '$install_check']]);` must return a Receipt whose
   `reason` is 'install_check' (it is never stored or billed). Report the result.
```

(b) Facts for agents:

- Package `mirafive/sdk-php`, namespace `MiraFive`. Classes: `MiraFive\Mira`, `MiraFive\Mode` (`Full`, `Consentless`), `MiraFive\Receipt`, `MiraFive\MiraError`, `MiraFive\Flags\MiraFlags`, `MiraFive\Flags\UserFlags`, `MiraFive\Flags\Evaluation`, `MiraFive\Http\Psr18Transport`.
- Environment: `MIRAFIVE_SECRET_KEY` (required), `MIRAFIVE_HOST` (optional, default `https://events.mirafive.io`).
- The secret key never goes to a browser. Browsers use the website key with `@mirafive/sdk-browser` or the hosted tracker.
- `track()` and `identify()` buffer; the buffer is flushed on shutdown (once), on destruction, at `flushAt` events, or by `flush()`. `send()` is immediate and throws `MiraError`.
- `track()`/`flush()` never throw for transport reasons. Invalid input and identifiers in consentless mode throw `InvalidArgumentException`.
- `MiraError::$errorCode` holds the protocol code; `getCode()` is the HTTP status.
- Verify a setup with `$mira->send([['name' => '$install_check']])`: the receipt's `reason` is `install_check`.
- Framework packages: [`mirafive/sdk-laravel`](https://packagist.org/packages/mirafive/sdk-laravel) (facade `MiraFive\Laravel\Facades\Mira`, flush on terminating) and [`mirafive/sdk-symfony`](https://packagist.org/packages/mirafive/sdk-symfony) (`MiraFive\Symfony\MiraFiveBundle`, flush on `kernel.terminate`).

## License

MIT, see [LICENSE](LICENSE). Copyright (c) 2026 Cloo GmbH.
