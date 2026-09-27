<?php

declare(strict_types=1);

namespace MiraFive;

use Closure;
use JsonException;
use MiraFive\Http\Response;
use MiraFive\Http\Transport;
use MiraFive\Http\TransportException;
use Throwable;

/** @internal One batch to POST /v1/batch, retried with the byte-identical body (PROTOCOL §5). */
final readonly class Delivery
{
    public const int MAX_BODY_BYTES = 1_048_576;

    private const int BACKOFF_BASE_MS = 100;

    private const int BACKOFF_CAP_MS = 1_000;

    /**
     * @param  Closure(int): void  $sleep  milliseconds
     */
    public function __construct(
        private Transport $transport,
        private string $host,
        private string $key,
        private int $timeoutMs,
        private int $maxRetries,
        private int $maxRetryAfterMs,
        private Closure $sleep,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $events  wire events
     *
     * @throws MiraError
     */
    public function deliver(array $events, string $batchId, Mode $mode): Receipt
    {
        if ($this->key === '') {
            throw new MiraError('unauthorized', 'No secret key: pass one to Mira or set MIRAFIVE_SECRET_KEY.');
        }

        $body = self::body($events, $batchId, $mode);

        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new MiraError('payload_too_large', 'The batch encodes to more than 1 MiB; send fewer events at once.', 413);
        }

        for ($attempt = 0; ; $attempt++) {
            $outcome = $this->attempt($body, $batchId);

            if ($outcome instanceof Receipt) {
                return $outcome;
            }

            $wait = $this->backoff($attempt, $outcome);

            if ($wait === null) {
                throw $outcome;
            }

            ($this->sleep)($wait);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private static function body(array $events, string $batchId, Mode $mode): string
    {
        try {
            return json_encode([
                'v' => 1,
                'batch' => $batchId,
                'mode' => $mode->value,
                'sentAt' => (int) floor(microtime(true) * 1000),
                'context' => ['sdk' => Mira::SDK],
                'events' => $events,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new MiraError('invalid_event', 'The batch cannot be encoded as JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function attempt(string $body, string $batchId): Receipt|MiraError
    {
        try {
            $response = $this->transport->request('POST', $this->host.'/v1/batch', [
                'Authorization' => 'Bearer '.$this->key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => Mira::SDK.' (PHP '.PHP_VERSION.')',
            ], $body, $this->timeoutMs);
        } catch (TransportException $exception) {
            return new MiraError($exception->timedOut ? 'timeout' : 'network_error', $exception->getMessage(), retryable: true, previous: $exception);
        } catch (Throwable $exception) {
            // A custom transport that breaks its contract must still not make flush() throw.
            return new MiraError('unexpected', $exception->getMessage(), previous: $exception);
        }

        return $response->status >= 200 && $response->status < 300
            ? self::receipt($response, $batchId)
            : self::refusal($response);
    }

    private static function receipt(Response $response, string $batchId): Receipt
    {
        $answer = $response->json();
        $answer = is_array($answer) ? $answer : [];

        return new Receipt(
            is_string($answer['batch'] ?? null) ? $answer['batch'] : $batchId,
            is_int($answer['accepted'] ?? null) ? $answer['accepted'] : 0,
            is_int($answer['dropped'] ?? null) ? $answer['dropped'] : 0,
            is_string($answer['reason'] ?? null) ? $answer['reason'] : null,
        );
    }

    public static function refusal(Response $response): MiraError
    {
        $answer = $response->json();
        $answer = is_array($answer) ? $answer : [];
        $status = $response->status;
        $code = is_string($answer['code'] ?? null) ? $answer['code'] : match ($status) {
            401 => 'unauthorized',
            408 => 'timeout',
            413 => 'payload_too_large',
            429 => 'rate_limited',
            default => 'unexpected',
        };
        $detail = is_string($answer['detail'] ?? null) ? $answer['detail'] : 'the collector answered '.$status;
        $errors = [];

        foreach (is_array($answer['errors'] ?? null) ? $answer['errors'] : [] as $error) {
            if (is_array($error) && is_string($error['path'] ?? null) && is_string($error['message'] ?? null)) {
                $errors[] = ['path' => $error['path'], 'message' => $error['message']];
            }
        }

        return new MiraError($code, "{$status} {$code}: {$detail}", $status, MiraError::retryableStatus($status), self::retryAfterMs($response), $errors);
    }

    /** Null when the error is final, or asks for a longer wait than a web request can afford. */
    private function backoff(int $attempt, MiraError $error): ?int
    {
        if (! $error->retryable || $attempt >= $this->maxRetries) {
            return null;
        }

        if ($error->retryAfterMs !== null) {
            return $error->retryAfterMs > $this->maxRetryAfterMs ? null : $error->retryAfterMs;
        }

        return random_int(0, min(self::BACKOFF_CAP_MS, self::BACKOFF_BASE_MS * 2 ** $attempt));
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
