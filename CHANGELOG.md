# Changelog

## 0.5.0 — 2026-09-27

First release on the v1 ingest protocol.

- `MiraFive\Mira`: buffered `track()` and `identify()`, immediate `send()` with idempotency keys (batch id per PROTOCOL §5), `flush()` on shutdown and destruction, batches of at most 1000 events and 1 MiB.
- Retries for 408, 429, 5xx, timeouts and network errors with full-jitter backoff and a bounded `Retry-After`, resending the byte-identical body.
- Consentless and full collection modes; identifiers in consentless mode are refused.
- Transports: cURL, PHP streams, or any PSR-18 client.
- `MiraFive\Flags\MiraFlags`: the server flag document with ETag revalidation and an optional PSR-16 cache, consent and opt-out per FLAGS.md §5.1, segment lookups, server-counted exposures and an escaped bootstrap block for the browser SDK.
- The flag evaluator passes the shared MIRA FIVE fixtures.
- Outage protection: a per-flush deadline (`flushDeadlineMs`, 3 s), a separate connect timeout (`connectTimeoutMs`, 1 s) and a 30 s circuit breaker after a failed flush or segment lookup, shared through the PSR-16 cache.
- Events the collector refuses (`validation_failed`) are dropped and reported; the rest of the batch is resent under a derived batch id.
- Exposure marks, segment memberships and the lookup back-off live in the PSR-16 cache when one is configured.
- `MiraFlags::snapshot()` returns the document JSON, which `document:` accepts; an unreadable `document` is reported.
- Property sizes are measured as the collector measures them; an empty idempotency key is refused.
- Seams for framework integrations: `handOff` and `deliverPrepared()` for queued delivery, `flushOnShutdown`, `enabled: false` for local and test environments, `flagsRefreshSeconds`, and `Mira::DEFAULT_HOST`.
