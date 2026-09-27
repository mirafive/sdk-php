<?php

declare(strict_types=1);

namespace MiraFive\Http;

use CurlHandle;

final class CurlTransport implements Transport
{
    /** Kept between requests so the flag fetch, a lookup and the batch share one connection. */
    private ?CurlHandle $handle = null;

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        if ($url === '' || $method === '') {
            throw new TransportException('A request needs a method and a URL.');
        }

        $handle = $this->handle ??= curl_init();
        curl_reset($handle);

        $received = [];
        // An empty Expect stops curl from waiting on "100 Continue" before larger bodies.
        $lines = ['Expect:'];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $answer = curl_exec($handle);

        if (! is_string($answer)) {
            throw new TransportException(curl_error($handle), curl_errno($handle) === CURLE_OPERATION_TIMEDOUT);
        }

        /** @var array<string, string> $received */
        return new Response(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $received, $answer);
    }
}
