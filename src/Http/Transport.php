<?php

declare(strict_types=1);

namespace MiraFive\Http;

/** One HTTP exchange. Implementations never follow redirects and throw TransportException only when no answer came. */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws TransportException
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response;
}
