<?php

declare(strict_types=1);

namespace MiraFive;

use Closure;
use Psr\Log\LoggerInterface;

/** @internal Where failures that must not throw go: onError, else a PSR-3 logger, else error_log(). */
final readonly class Reporter
{
    /** @var (Closure(MiraError): void)|null */
    private ?Closure $onError;

    /**
     * @param  (callable(MiraError): void)|null  $onError
     */
    public function __construct(?callable $onError = null, private ?LoggerInterface $logger = null)
    {
        $this->onError = $onError === null ? null : $onError(...);
    }

    public function report(MiraError $error): void
    {
        if ($this->onError !== null) {
            ($this->onError)($error);
        } elseif ($this->logger !== null) {
            $this->logger->warning('[mirafive] {message}', ['message' => $error->getMessage(), 'exception' => $error]);
        } else {
            error_log('[mirafive] '.$error->getMessage());
        }
    }
}
