# MIRA FIVE for PHP

The server SDK of [MIRA FIVE](https://mirafive.io), revenue attribution and analytics by Cloo GmbH,
compliant with GDPR and TDDDG. Send what only your server knows (a paid invoice, a plan change, a
survey answer), revenue for the ledger, and backfills. The events join the browser's events on the
MIRA server, where sessions, identity and attribution happen.

- PHP 8.3 or newer. No required Composer dependencies; cURL is used when present, PHP streams
  otherwise, or your own PSR-18 client.
- Consent-aware: every event says which consent it was collected under, and MIRA keeps what that
  consent tier allows.
- Never throws from the event methods: problems go to `onError` or your PSR-3 logger.
- Same wire format and semantics as the Node SDK (`@mirafive/node`), including `eventIdFor()`, so a
  conversion reported by PHP and by Node counts once.

Laravel or Symfony? Use [`mirafive/sdk-laravel`](https://github.com/mirafive/sdk-laravel) or
[`mirafive/sdk-symfony`](https://github.com/mirafive/sdk-symfony): they configure this client,
flush after the response, add queues, the script tag and test fakes.

## Install

```sh
composer require mirafive/sdk-php
```

Set the project's secret key (MIRA FIVE dashboard, Settings, Sources):

```sh
MIRAFIVE_SECRET_KEY=mira_sk_…
```

## Quickstart

```php
<?php

use MiraFive\Consent;
use MiraFive\Mira;

// Reads MIRAFIVE_SECRET_KEY and MIRAFIVE_HOST. Keep one client per request or process.
$mira = new Mira();

// Who the user is. PII traits (email, name, phone) are encrypted at rest by MIRA.
$mira->identify(
    userId: 'user_42',
    traits: ['email' => 'ada@example.com', 'plan' => 'pro'],
    externalIds: ['stripe_customer' => 'cus_123'], // so the Stripe connector links its money to this user
);

// Something the user did, with the consent your banner recorded.
$mira->track(
    event: 'report_exported',
    userId: 'user_42',
    properties: ['format' => 'csv'],
    consent: Consent::analyticsOnly(),
    context: ['ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null],
);

// Money, in integer minor units: 4900 is 49.00 EUR.
$mira->revenue(
    amount: 4900,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'stripe:in_123',                      // the same money from the Stripe connector counts once
    eventId: Mira::eventIdFor('order:123:paid'),   // a retried job counts once
);

// Events leave in batches when 100 are waiting, on flush(), and when the request ends.
```

That is all a plain PHP app needs: the client flushes in a shutdown function when the script ends.
Under PHP-FPM, call `fastcgi_finish_request()` before that if the visitor should not wait for it
(the flush never takes longer than `flushDeadlineMs`), or hand batches to a [queue](#queues).
In a long-running process (a worker, a daemon), call `$mira->flush()` after each unit of work and
`$mira->shutdown()` before the process exits.

## Environment

| Variable              | Default                      | Meaning                                                               |
| --------------------- | ---------------------------- | --------------------------------------------------------------------- |
| `MIRAFIVE_SECRET_KEY` | (required to send)           | The project's secret key, `mira_sk_…`. Server only, never in a page   |
| `MIRAFIVE_HOST`       | `https://events.mirafive.io` | The ingest origin, for a self-hosted MIRA FIVE or a local one         |
| `MIRAFIVE_PUBLIC_KEY` | (none)                       | The public key, `mira_pk_…`, for the browser script tag (`ScriptTag`) |

Constructor arguments win over the environment. A public key (`mira_pk_…`) in place of the secret
key is refused like a missing one: the client reports it once and sends nothing, as every MIRA FIVE
server SDK does. Public keys belong in browsers.

## API reference

All methods take named arguments; the examples use them. Every event method also takes these
common arguments:

| Argument      | Type                                     | Meaning                                                                                                  |
| ------------- | ---------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `userId`      | `?string`                                | Your own id for the person. Never an email address                                                       |
| `anonymousId` | `?string`                                | The browser's anonymous id, when you have it                                                             |
| `eventId`     | `?string`                                | A UUID; set it to report one fact from several places only once (see `eventIdFor`). Default: a UUIDv7    |
| `timestamp`   | `DateTimeInterface\|int\|string\|null`   | When it happened: a date, epoch milliseconds or an ISO 8601 string. Past dates are fine (backfills)      |
| `consent`     | `Consent\|'not_applicable'\|array\|null` | The consent the event was collected under. Default `not_applicable`; see [Consent tiers](#consent-tiers) |
| `context`     | `?array`                                 | What you know about the end user's request; see [Context](#context)                                      |

### `new Mira(...)`

| Argument           | Default                         | Meaning                                                                                             |
| ------------------ | ------------------------------- | --------------------------------------------------------------------------------------------------- |
| `secretKey`        | `MIRAFIVE_SECRET_KEY`           | The project's secret key                                                                            |
| `host`             | `MIRAFIVE_HOST`, else the cloud | The ingest origin, `https://…` or `http://…`                                                        |
| `flushAt`          | `100`                           | Send as soon as this many events are waiting (1 to 100, the ingest's batch limit)                   |
| `timeoutMs`        | `3000`                          | Per request                                                                                         |
| `connectTimeoutMs` | `1000`                          | Per connection (cURL)                                                                               |
| `flushDeadlineMs`  | `3000`                          | What one flush may spend on attempts and waits together, so an outage cannot hold up your request   |
| `maxRetries`       | `2`                             | Retries per batch on network errors, timeouts, 408, 429 and 5xx                                     |
| `maxRetryAfterMs`  | `3000`                          | A longer `Retry-After` is not waited for; the batch is reported instead                             |
| `onError`          | `null`                          | `callable(MiraError): void`; receives every problem                                                 |
| `logger`           | `null`                          | A PSR-3 logger, used when there is no `onError`; else `error_log()`                                 |
| `cache`            | `null`                          | A PSR-16 cache that shares the delivery breaker between PHP-FPM workers                             |
| `transport`        | cURL, else streams              | `Http\Psr18Transport` wraps your PSR-18 client; `Testing\RecordingTransport` records                |
| `enabled`          | `true`                          | `false`: input is still checked, but nothing leaves the process and no key is needed                |
| `flushOnShutdown`  | `true`                          | `false` when your framework flushes after the response                                              |
| `handOff`          | `null`                          | `Closure(string $payload): void`; receives each batch instead of sending it (see [Queues](#queues)) |
| `clock`            | `null`                          | A PSR-20 clock for default event times and `sent_at`                                                |

### `track(event, properties: [], …)`

A custom event. Names starting with `$` are reserved for MIRA.

```php
$mira->track(event: 'plan_changed', userId: 'user_42', properties: ['from' => 'basic', 'to' => 'pro']);
```

Properties are cleaned as every MIRA FIVE SDK cleans them: nested arrays and objects become dot keys
(`['plan' => ['name' => 'pro']]` is `plan.name`) up to three levels, lists keep up to 50 scalars,
strings are cut at 2048 characters and keys at 100, at most 64 properties are kept, and dates become
ISO strings. Empty properties are sent as `{}`.

### `identify(userId, traits: [], externalIds: [], …)`

Who the user is. PII traits (`email`, `name`, `phone`, `address`, …) are encrypted at rest; every
other trait stays queryable.

`externalIds` are the person's ids in other systems, so connectors can link their money to this
user: `stripe_customer`, `revenuecat_app_user`, `shopify_customer`, `shopware_customer`. An unknown
type is reported and dropped; an id over 200 characters is dropped, never cut short.

### `group(groupId, userId: null, traits: [], …)`

The company or account a user belongs to (B2B), with its traits: name, size, plan.

```php
$mira->group(groupId: 'acme', userId: 'user_42', traits: ['name' => 'Acme GmbH', 'seats' => 12]);
```

### `revenue(amount, currency, …)`

Money, as `$revenue`. Every call becomes one entry in the project's revenue ledger, keyed by
`entryId`: sent twice, it counts once. Only secret keys may send revenue.

| Argument       | Meaning                                                                                                                                                            |
| -------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `amount`       | Integer minor units: cents for EUR and USD, yen for JPY. `1999` is 19.99 EUR. Negative for refunds                                                                 |
| `currency`     | ISO 4217, for example `EUR`                                                                                                                                        |
| `entryId`      | The provider object this money belongs to (`stripe:in_123`, `crm:case-81`)                                                                                         |
| `entryKind`    | `charge` (one-time, the default), `subscription_payment` (the default with `subscription`), `refund` (the default for a negative amount), `dispute`, `dispute_won` |
| `fee`          | Processor fees or store commission, in minor units                                                                                                                 |
| `tax`          | Tax included in `amount`, in minor units. Proceeds are the amount minus `fee` and `tax`                                                                            |
| `refundOf`     | For a refund or dispute: the `entryId` of the money it takes back                                                                                                  |
| `product`      | The product or plan, for example `premium_monthly`                                                                                                                 |
| `subscription` | `['id' => …, 'interval' => 'day'\|'week'\|'month'\|'year', 'intervalCount' => 1..36, 'periodStart' => …, 'periodEnd' => …]`                                        |
| `properties`   | Your own properties                                                                                                                                                |

```php
// A subscription payment (the first one and every renewal), with store commission and VAT.
$mira->revenue(
    amount: 999,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'revenuecat:txn_1000000123',
    fee: 150,
    tax: 160,
    product: 'premium_monthly',
    subscription: ['id' => 'sub_42', 'interval' => 'month'], // pays from now for one month
);

// Quarterly billing, with an explicit period.
$mira->revenue(
    amount: 2700,
    currency: 'EUR',
    userId: 'user_42',
    entryId: 'stripe:in_456',
    subscription: [
        'id' => 'sub_43',
        'interval' => 'month',
        'intervalCount' => 3,
        'periodStart' => '2026-11-01T00:00:00Z',
        'periodEnd' => '2027-02-01T00:00:00Z',
    ],
);

// A refund takes the money back, and the credit of the channels that earned it.
$mira->revenue(
    amount: -999,
    currency: 'EUR',
    userId: 'user_42',
    entryKind: 'refund',
    entryId: 'revenuecat:txn_1000000123:refund',
    refundOf: 'revenuecat:txn_1000000123',
);
```

MRR comes from subscription payments and the period each pays for. A subscription payment inherits
the attribution of the person's acquisition (their first conversion, sign-up, trial or first
purchase, set per project), a refund that of the money it takes back.

A float amount is accepted only when it is a whole number (`1999.0`); `19.99` is reported and the
event is dropped, because money is never rounded silently.

### `touchpoint(kind, …)`

A touchpoint that is not a visit, as `$touchpoint`: the answer to "How did you hear about us?", a
trade fair contact, a redeemed code. The attribution models credit it like a visit. It needs a
`userId` or an `anonymousId`, and a `channel` or a `source`.

| Argument                          | Meaning                                                                                                                          |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `kind`                            | `survey`, `offline`, `code` or `custom`                                                                                          |
| `channel`                         | One of MIRA's channels (`Mira::TOUCHPOINT_CHANNELS`): `paid_search`, `paid_social`, `affiliate`, `video`, `email`, `referral`, … |
| `source`, `medium`, `campaign`    | Without a channel, these decide it like the UTMs of a link, the project's channel rules included                                 |
| `content`                         | The ad or creative, as `utm_content` names it for a visit                                                                        |
| `campaignId`, `adGroupId`, `adId` | Ids from the ad platform                                                                                                         |
| `code`                            | The discount or partner code                                                                                                     |
| `answer`                          | The survey answer as the person gave it. It passes MIRA's PII guard                                                              |
| `externalRef`                     | Your record, such as a CRM contact (`hubspot:123`)                                                                               |

```php
// "How did you hear about us?"
$mira->touchpoint(
    kind: 'survey',
    userId: 'user_42',
    source: 'podcast',
    answer: 'The Elternzeit podcast',
    eventId: Mira::eventIdFor('survey:response_77'), // a re-sent answer counts once
);

// A trade fair contact from your CRM.
$mira->touchpoint(kind: 'offline', userId: 'user_42', channel: 'referral', source: 'trade_fair_2026', externalRef: 'hubspot:123');

// A redeemed partner code.
$mira->touchpoint(kind: 'code', userId: 'user_42', channel: 'affiliate', source: 'partner_x', code: 'PARTNER20');
```

### `with(userId: null, anonymousId: null, consent: null, context: null)`

A client that fills in these values for every event; the call's own values win, and `context` is
merged. It shares the buffer with the client it came from. Use it per request or per user:

```php
$request = $mira->with(
    userId: (string) $user->id,
    consent: ['analytics' => $banner->analytics, 'marketing' => $banner->marketing],
    context: ['ip' => $_SERVER['REMOTE_ADDR'], 'userAgent' => $_SERVER['HTTP_USER_AGENT']],
);
$request->track(event: 'report_exported');
```

### `Mira::eventIdFor(string $key): string`

A stable event id from your own key, such as `order:123:paid`. The same key always gives the same
UUID, in PHP, Node and every other MIRA FIVE SDK (a UUIDv8 from the first 122 bits of SHA-256). A
retried job, a backfill run twice, or the browser and the server reporting one conversion all count
once.

### `flush()`, `shutdown()`

`flush()` sends everything that is waiting, within `flushDeadlineMs`, and never throws.
`shutdown()` flushes and refuses new events (reported as `…: the client was shut down`).

### `deliverPrepared(string $payload): BatchResult`

Sends a batch that a `handOff` received, in a queue worker. See [Queues](#queues).

### `checkConnection(): void`

Proves that the secret key and host work, without sending an event: it asks the ingest's
`GET /v1/check`, which stores and counts nothing. Returns the key's kind (`secret`).
Throws a `MiraError` when the host cannot be reached or refuses the key.

### `MiraError`

What `onError` receives, a `RuntimeException`:

| Property       | Meaning                                                                           |
| -------------- | --------------------------------------------------------------------------------- |
| `status`       | The HTTP status when the ingest answered (`202` for rejected events), else `null` |
| `eventIds`     | The `event_id`s that were not delivered                                           |
| `retryable`    | A network error, a timeout, 408, 429 or 5xx: trying later may work                |
| `retryAfterMs` | The ingest's `Retry-After`, in milliseconds                                       |
| `reason`       | The ingest's error code: `unknown_key`, `invalid_batch`, `rate_limited`, …        |

### `ScriptTag::render(publicKey, …)`

The browser script tag, exactly as the dashboard shows it: a stub that queues calls made before the
SDK has loaded, then the SDK. It refuses a secret key (throws `MiraError`), so a misconfigured key
never reaches a page.

```php
echo MiraFive\ScriptTag::render(
    publicKey: getenv('MIRAFIVE_PUBLIC_KEY') ?: '',
    consent: 'auto',                    // or granted, denied, analytics (analytics yes, marketing no)
    autocapture: ['forms' => false],    // or false for none; parts: spa, engagement, outbound, downloads, forms
    nonce: $cspNonce,                   // optional
);
```

## Context

| Key         | Meaning                                                                                                                                                                                                                 |
| ----------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ip`        | The end user's IP, for geo. Used at ingest and never stored                                                                                                                                                             |
| `userAgent` | The end user's user agent, for the device                                                                                                                                                                               |
| `page`      | `['url' => …, 'referrer' => …, 'title' => …]`                                                                                                                                                                           |
| `app`       | Your app's `name`, `version` and `build`, for events from an app backend                                                                                                                                                |
| `locale`    | The end user's locale, `de-DE`                                                                                                                                                                                          |
| `timezone`  | The end user's IANA time zone, `Europe/Berlin`                                                                                                                                                                          |
| `location`  | `['latitude' => …, 'longitude' => …]`: only one your app already has, and only with analytics consent. MIRA rounds it to 2 decimals (about 1 km). An invalid location is dropped and reported; the event still goes out |

## Consent tiers

Server events default to `not_applicable`: a paid invoice is a fact of your business, not
something a visitor did. For events that describe what a visitor did, pass what your consent banner
recorded: `Consent::granted()`, `Consent::analyticsOnly()`, `Consent::denied()`,
`Consent::of(analytics: true, marketing: false)`, or an array such as
`['analytics' => true, 'marketing' => false]` (booleans or `granted`, `denied`, `unknown`).

| `consent` on the event                           | Tier    | What MIRA keeps                                                                               |
| ------------------------------------------------ | ------- | --------------------------------------------------------------------------------------------- |
| `not_applicable` (default), no `userId`          | T1      | The event, `anonymous_id` if given, geo from `context.ip` (the IP is discarded)               |
| `not_applicable` with `userId`                   | T2      | Plus `user_id`, traits (PII traits encrypted at rest), revenue per person                     |
| `{ analytics: 'granted' }`                       | T1 / T2 | As above                                                                                      |
| `{ analytics: 'granted', marketing: 'granted' }` | T3      | Plus click ids, and later forwarding to ad platforms                                          |
| `{ analytics: 'denied' \| 'unknown' }`           | T0      | No `user_id`, `anonymous_id` or `session_id`: the event counts, but is not linked to a person |

## Delivery

- **Batches:** at most 100 events and about 500 kB per request to `POST {host}/v1/batch`, one batch
  at a time, so their order is kept.
- **Retries:** network errors, timeouts, 408, 429 and 5xx are retried with exponential backoff and
  full jitter, never sooner than `Retry-After`, always with the same event ids. Other 4xx answers
  are final and reported.
- **Sequence numbers:** every batch carries a random `stream` id per client and a `seq` from 1 (a
  retry keeps its number), so MIRA can count batches that never arrived.
- **Outages:** a flush never takes longer than `flushDeadlineMs`. After a batch failed for good, the
  client skips the network for 30 s and reports the dropped events once, so an outage of the ingest
  cannot slow down every request. Pass a PSR-16 `cache` to share this between PHP-FPM workers.
- **Rejected events:** the ingest validates each event on its own. A rejected event is reported with
  its id and reason; the rest of the batch is stored.

## Queues

To keep the request free of network calls, hand batches to your queue and send them from a worker:

```php
// In the web request: each batch payload goes to your queue. It carries no key.
$mira = new Mira(handOff: function (string $payload) use ($queue): void {
    $queue->push('mirafive', $payload);
});

// In the worker: send it with the worker's own key and the usual retries.
try {
    $mira->deliverPrepared($payload);
} catch (MiraFive\MiraError $error) {
    if ($error->retryable) {
        throw $error; // let the queue try again later: the same events and seq, counted once
    }
    $logger->warning($error->getMessage()); // final: the ingest refused the batch
}
```

`sent_at` is set when the worker sends, so a batch that waited in the queue keeps its events' times.

## Testing

`MiraFive\Testing\MiraFake` is a real client on a recording transport. Input is checked exactly as
in production, so an event the ingest would refuse fails the test the same way.

```php
use MiraFive\Testing\MiraFake;

$fake = MiraFake::create();
(new Checkout($fake->mira))->complete($order);

$fake->assertRevenue(fn (array $event) => $event['revenue']['amount'] === 4900);
$fake->assertTracked('checkout_completed', 1);           // exactly once
$fake->assertIdentified('user_42');
$fake->assertTouchpoint(fn (array $event) => $event['properties']['source'] === 'podcast');
$fake->assertNotTracked('checkout_failed');
$fake->assertNoErrors();                                   // nothing was refused or dropped
```

Also: `assertGrouped()`, `assertNothingTracked()`, `events(?name)`, `errors()`, `clear()`, and
`$fake->transport->answerNext(new MiraFive\Http\Response(503))` to test failures. Callbacks get the
wire event, with snake_case keys (`user_id`, `properties`, `revenue`, `traits`, `external_ids`).

## Troubleshooting

- **Nothing arrives.** Run `$mira->checkConnection()` once, or pass
  `onError: fn ($e) => error_log($e->getMessage())`. Without a secret key the client reports
  `no secret key` once and drops events.
- **`batch dropped: HTTP 401 unknown_key`.** The key is wrong, revoked, or from another MIRA FIVE
  host. Server code needs the secret key (`mira_sk_…`), not the public one.
- **`1 event(s) rejected: forbidden: $revenue needs a secret key`.** The client was given a public
  key.
- **`revenue: amount must be an integer in minor units`.** Send cents: `1999`, not `19.99`.
- **Events from a worker or daemon arrive late or not at all.** A long-running process never ends
  its request: call `$mira->flush()` after each job and `$mira->shutdown()` before it exits.
- **`delivery failed recently; events are dropped …`.** The ingest could not be reached; the client
  waits 30 s before it tries again. Check outgoing HTTPS to `MIRAFIVE_HOST`.

## Development

```sh
composer install
composer check                     # pint --test, phpstan (max, strict), pest
php tests/contract/emit.php        # the batches of a fixed scenario, for the wire schema check
```

## License

MIT, Cloo GmbH. See [LICENSE](LICENSE).
