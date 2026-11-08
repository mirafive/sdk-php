# Changelog

## 2.0.0 — 2026-11-08

A rewrite for the MIRA FIVE platform. The 1.x SDK spoke the retired v1 collector protocol; 2.0
speaks the MIRA FIVE ingest and mirrors the Node SDK (`@mirafive/node`) method for method.

### New

- `group()` for B2B accounts, `revenue()` for the revenue ledger (integer minor units, `entryId`,
  `entryKind`, `fee`, `tax`, `refundOf`, `product`, subscriptions with their billing period), and
  `touchpoint()` for survey answers, offline contacts and redeemed codes.
- `identify()` takes `externalIds` (`stripe_customer`, `revenuecat_app_user`, `shopify_customer`,
  `shopware_customer`), so connectors link their money to the user.
- Per-event `consent` (default `not_applicable`) with the `Consent` helper, and `context` (`ip`,
  `userAgent`, `page`, `app`, `locale`, `timezone`, `location`).
- `Mira::eventIdFor()`: stable event ids from your own keys, byte-identical to the Node SDK.
- `with()`: a client that fills in the user, consent and context for every event.
- `checkConnection()` (asks `GET /v1/check`, sends no event), `ScriptTag::render()` for the browser SDK, and `Testing\MiraFake` with
  assertions.
- Every batch carries a `stream` and a `seq`, so MIRA can count lost batches.

### Changed

- The default host is `https://events.mirafive.io`; batches go to `POST /v1/batch` with at most
  100 events (was 1000) and about 500 kB.
- Keys are `mira_sk_…` (secret) and `mira_pk_…` (public). A public key in place of the secret key is
  refused with a clear message, and nothing is sent.
- Event methods never throw any more: invalid input is reported to `onError` (or the logger) and the
  event is dropped, like a delivery problem.
- The wire format is snake_case (`event`, `timestamp`, `user_id`, `anonymous_id`, …) with ISO 8601
  times; nested properties are flattened to dot keys up to depth 3.
- `MiraError` has `status`, `eventIds`, `retryable`, `retryAfterMs` and `reason` (the ingest's error
  code).
- Hand-off payloads are `{stream, seq, events}`; `sent_at` is added when the worker sends.

### Removed

- Collection modes (`Mode`, `consentless` and `full`): consent is now stamped per event.
- `send()`, idempotency keys, batch ids and `Receipt`: use `eventId` with `Mira::eventIdFor()` for
  facts that may be reported twice, and `flush()` to send now.
- `sessionId`: the MIRA server makes sessions.
- `$install_check`: use `checkConnection()`.
- The `payment_id` property: use `revenue(entryId: …)`.
- `mf_` keys and `collect.mirafive.io`.

### Migrating from 1.x

| 1.x                                                                                                 | 2.0                                                                              |
| --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------- |
| `new Mira(key: 'mf_…', mode: Mode::Full)`                                                           | `new Mira(secretKey: 'mira_sk_…')` (or `MIRAFIVE_SECRET_KEY`)                    |
| `track('signup', userId: 'u1', properties: [...])`                                                  | `track(event: 'signup', userId: 'u1', properties: [...])`                        |
| `track('order paid', properties: ['revenue' => 49.0, 'currency' => 'EUR', 'payment_id' => 'in_1'])` | `revenue(amount: 4900, currency: 'EUR', entryId: 'stripe:in_1')`                 |
| `identify('u1', ['email' => …])`                                                                    | `identify(userId: 'u1', traits: ['email' => …])`                                 |
| `time: 1727430000000`                                                                               | `timestamp: 1727430000000` (or a `DateTimeInterface` or ISO string)              |
| `page: ['url' => …]`                                                                                | `context: ['page' => ['url' => …]]`                                              |
| `send($events, idempotencyKey: 'order-1')`                                                          | one call per event with `eventId: Mira::eventIdFor('order-1:…')`, then `flush()` |
| `Mode::Consentless`                                                                                 | `consent: Consent::denied()` on events from visitors without consent             |
| `catch (InvalidArgumentException)` around `track()`                                                 | `onError: fn (MiraError $e) => …`                                                |
