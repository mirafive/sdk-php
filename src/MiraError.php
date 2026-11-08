<?php

declare(strict_types=1);

namespace MiraFive;

use RuntimeException;
use Throwable;

/**
 * A problem the client reports instead of throwing: invalid input, a batch the ingest refused or
 * never answered, events it rejected. Event methods hand it to `onError` (or the logger); only
 * `deliverPrepared()` and `checkConnection()` throw it.
 */
final class MiraError extends RuntimeException
{
    /**
     * @param  int|null  $status  the HTTP status, when the ingest answered
     * @param  list<string>  $eventIds  the `event_id`s that were not delivered
     * @param  bool  $retryable  a network error, a timeout, 408, 429 or 5xx: trying later may work
     * @param  string|null  $reason  the ingest's error code (`unknown_key`, `invalid_batch`, …)
     */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly array $eventIds = [],
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterMs = null,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function retryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * The same problem, about these events.
     *
     * @param  list<string>  $eventIds
     */
    public function about(array $eventIds): self
    {
        return new self($this->getMessage(), $this->status, $eventIds, $this->retryable, $this->retryAfterMs, $this->reason, $this->getPrevious());
    }
}
