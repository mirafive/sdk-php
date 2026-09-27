<?php

declare(strict_types=1);

namespace MiraFive;

use RuntimeException;
use Throwable;

/**
 * A batch the collector refused or never answered. `errorCode` holds the protocol code (`rate_limited`,
 * `validation_failed`, `network_error`, `timeout`, …); Exception::getCode() is the HTTP status, or 0.
 */
final class MiraError extends RuntimeException
{
    /**
     * @param  list<array{path: string, message: string}>  $errors
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterMs = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    public static function retryableStatus(int $status): bool
    {
        return $status === 408 || $status === 429 || $status >= 500;
    }
}
