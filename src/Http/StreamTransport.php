<?php

declare(strict_types=1);

namespace MiraFive\Http;

/** The fallback when ext-curl is missing. PHP streams have one timeout, for connecting and reading alike. */
final class StreamTransport implements Transport
{
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs, int $connectTimeoutMs): Response
    {
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $body ?? '',
            'timeout' => $timeoutMs / 1000,
            'ignore_errors' => true,
            'follow_location' => 0,
            'protocol_version' => 1.1,
        ]]);

        $error = null;
        set_error_handler(static function (int $level, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        $started = hrtime(true);

        try {
            $stream = fopen($url, 'r', false, $context);

            if ($stream === false) {
                // A timeout before the headers arrive only shows as a failed open.
                throw new TransportException($error ?? 'the request failed', (hrtime(true) - $started) / 1e6 >= $timeoutMs);
            }

            $answer = stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
            fclose($stream);
        } finally {
            restore_error_handler();
        }

        if ($answer === false || $meta['timed_out']) {
            throw new TransportException($error ?? 'the request timed out', $meta['timed_out']);
        }

        [$status, $received] = self::parse(is_array($meta['wrapper_data']) ? $meta['wrapper_data'] : []);

        return new Response($status, $received, $answer);
    }

    /**
     * @param  array<mixed>  $lines
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parse(array $lines): array
    {
        $status = 0;
        $headers = [];

        foreach ($lines as $line) {
            if (! is_string($line)) {
                continue;
            }

            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, $headers];
    }
}
