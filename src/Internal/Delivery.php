<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use Closure;
use MiraFive\BatchResult;
use MiraFive\Http\Response;
use MiraFive\Http\Transport;
use MiraFive\Http\TransportException;
use MiraFive\Mira;
use MiraFive\MiraError;
use Throwable;

/**
 * @internal Sends one batch to `POST /v1/batch` with retries: network errors, timeouts, 408, 429
 * and 5xx are retried with full-jitter backoff, never sooner than `Retry-After`; other answers are
 * final. Every attempt carries the same events and `seq`, so the ingest deduplicates.
 */
final readonly class Delivery
{
    private const int BACKOFF_BASE_MS = 100;

    private const int BACKOFF_CAP_MS = 1_000;

    /**
     * @param  Closure(int): void  $sleep  milliseconds
     * @param  Closure(): string  $now  the current time, ISO 8601
     */
    public function __construct(
        private Transport $transport,
        private string $endpoint,
        private string $key,
        private int $timeoutMs,
        private int $connectTimeoutMs,
        private int $maxRetries,
        private int $maxRetryAfterMs,
        private Closure $sleep,
        private Closure $now,
    ) {}

    /**
     * @param  Budget|null  $budget  caps attempts and waits together; each attempt's timeout shrinks to fit
     *
     * @throws MiraError when the batch was not delivered; `retryable` tells whether a later try may work
     */
    /**
     * Asks the ingest whether the key works (`GET /v1/check`); nothing is stored or counted.
     *
     * @return 'public'|'secret'
     *
     * @throws MiraError
     */
    public function check(): string
    {
        $url = preg_replace('#/v1/batch$#', '/v1/check', $this->endpoint) ?? $this->endpoint;

        try {
            $response = $this->transport->request('GET', $url, [
                'Authorization' => 'Bearer '.$this->key,
                'Accept' => 'application/json',
                'User-Agent' => Mira::LIBRARY.'/'.Mira::VERSION.' (PHP '.PHP_VERSION.')',
            ], null, $this->timeoutMs, min($this->connectTimeoutMs, $this->timeoutMs));
        } catch (TransportException $exception) {
            throw new MiraError("cannot reach {$url}: is MIRAFIVE_HOST right? ({$exception->getMessage()})", previous: $exception);
        }

        $body = $response->json();
        $kind = is_array($body) ? ($body['key'] ?? null) : null;

        if ($response->status === 200 && is_array($body) && ($body['ok'] ?? false) === true && ($kind === 'public' || $kind === 'secret')) {
            return $kind;
        }

        if ($response->status === 401) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'unauthorized';
            throw new MiraError("HTTP 401 {$error}: the ingest refused the key, check MIRAFIVE_SECRET_KEY", 401);
        }

        throw new MiraError("{$url} answered HTTP {$response->status}, but not like a MIRA FIVE ingest: is MIRAFIVE_HOST right?", $response->status);
    }

    public function send(Batch $batch, ?Budget $budget = null): BatchResult
    {
        for ($attempt = 0; ; $attempt++) {
            if ($budget !== null && $budget->remainingMs <= 0) {
                throw new MiraError('batch dropped: the flush deadline passed', eventIds: $batch->eventIds, retryable: true);
            }

            $timeoutMs = min($this->timeoutMs, $budget->remainingMs ?? PHP_INT_MAX);
            $started = hrtime(true);
            $outcome = $this->attempt($batch, $timeoutMs);
            $budget?->spend((int) ceil((hrtime(true) - $started) / 1e6));

            if ($outcome instanceof BatchResult) {
                return $outcome;
            }

            if (! $outcome->retryable) {
                throw $outcome;
            }

            $attempts = $attempt + 1;
            $wait = $this->backoff($attempt, $outcome);

            if ($attempt >= $this->maxRetries || $wait === null || ($budget !== null && $wait >= $budget->remainingMs)) {
                throw new MiraError(
                    "batch dropped after {$attempts} attempt".($attempts === 1 ? '' : 's').': '.$outcome->getMessage(),
                    $outcome->status,
                    $batch->eventIds,
                    true,
                    $outcome->retryAfterMs,
                    $outcome->reason,
                    $outcome,
                );
            }

            $budget?->spend($wait);
            ($this->sleep)($wait);
        }
    }

    private function attempt(Batch $batch, int $timeoutMs): BatchResult|MiraError
    {
        try {
            $response = $this->transport->request('POST', $this->endpoint, [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => Mira::LIBRARY.'/'.Mira::VERSION.' (PHP '.PHP_VERSION.')',
            ], $batch->body(($this->now)()), $timeoutMs, min($this->connectTimeoutMs, $timeoutMs));
        } catch (TransportException $exception) {
            return new MiraError(
                ($exception->timedOut ? 'timeout: ' : 'network error: ').$exception->getMessage(),
                eventIds: $batch->eventIds,
                retryable: true,
                previous: $exception,
            );
        } catch (Throwable $exception) {
            // A custom transport that breaks its contract must still not make flush() throw.
            return new MiraError('transport failed: '.$exception->getMessage(), eventIds: $batch->eventIds, previous: $exception);
        }

        if ($response->status >= 200 && $response->status < 300) {
            return BatchResult::fromJson($response->json());
        }

        return self::refusal($response, $batch->eventIds);
    }

    /**
     * @param  list<string>  $eventIds
     */
    private static function refusal(Response $response, array $eventIds): MiraError
    {
        $body = $response->json();
        $status = $response->status;
        $reason = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
        $detail = is_array($body) && is_string($body['detail'] ?? null) ? ' ('.$body['detail'].')' : '';
        $hint = match ($reason) {
            'unknown_key', 'missing_key' => ': check MIRAFIVE_SECRET_KEY, it must be a secret key (mira_sk_…) of this project',
            'payload_too_large' => ': the batch is larger than 512 KiB',
            default => '',
        };

        return new MiraError(
            "batch dropped: HTTP {$status}".($reason === null ? '' : " {$reason}").$detail.$hint,
            $status,
            $eventIds,
            MiraError::retryableStatus($status),
            self::retryAfterMs($response),
            $reason,
        );
    }

    /** Null when the server asks for a longer wait than this client may spend. */
    private function backoff(int $attempt, MiraError $error): ?int
    {
        if ($error->retryAfterMs !== null && $error->retryAfterMs > $this->maxRetryAfterMs) {
            return null;
        }

        $jitter = random_int(0, min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * 2 ** $attempt));

        return max($error->retryAfterMs ?? 0, $jitter);
    }

    private static function retryAfterMs(Response $response): ?int
    {
        $header = trim($response->header('retry-after') ?? '');

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $at = strtotime($header);

        return $at === false ? null : max(0, ($at - time()) * 1000);
    }
}
