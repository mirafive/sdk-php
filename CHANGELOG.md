# Changelog

## 0.5.0 — 2026-09-27

First release on the v1 ingest protocol.

- `MiraFive\Mira`: buffered `track()` and `identify()`, immediate `send()` with idempotency keys (batch id per PROTOCOL §5), `flush()` on shutdown and destruction, batches of at most 1000 events and 1 MiB.
- Retries for 408, 429, 5xx, timeouts and network errors with full-jitter backoff and a bounded `Retry-After`, resending the byte-identical body.
- Consentless and full collection modes; identifiers in consentless mode are refused.
- Transports: cURL, PHP streams, or any PSR-18 client.
- `MiraFive\Flags\MiraFlags`: the server flag document with ETag revalidation and an optional PSR-16 cache, consent and opt-out per FLAGS.md §5.1, segment lookups, server-counted exposures and an escaped bootstrap block for the browser SDK.
- The flag evaluator passes the shared MIRA FIVE fixtures.
- Seams for framework integrations: `handOff` and `deliverPrepared()` for queued delivery, `flushOnShutdown`, `enabled: false` for local and test environments, `flagsRefreshSeconds`, and `Mira::DEFAULT_HOST`.
