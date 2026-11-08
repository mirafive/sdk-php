<?php

declare(strict_types=1);

namespace MiraFive\Internal;

use Closure;
use MiraFive\MiraError;
use Psr\Log\LoggerInterface;
use Throwable;

/** @internal Where problems go instead of exceptions: onError, else a PSR-3 logger, else error_log(). */
final readonly class Reporter
{
    /**
     * @param  (Closure(MiraError): void)|null  $onError
     */
    public function __construct(private ?Closure $onError = null, private ?LoggerInterface $logger = null) {}

    public function report(MiraError $error): void
    {
        try {
            if ($this->onError !== null) {
                ($this->onError)($error);
            } elseif ($this->logger !== null) {
                $this->logger->warning('[mirafive] {message}', ['message' => $error->getMessage(), 'exception' => $error, 'event_ids' => $error->eventIds]);
            } else {
                error_log('[mirafive] '.$error->getMessage());
            }
        } catch (Throwable) {
            // A throwing error handler must not break the caller either.
        }
    }
}
