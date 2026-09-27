<?php

declare(strict_types=1);

namespace MiraFive\Http;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Sends through your PSR-18 client. Its own timeout applies; configure it short, this runs inside your request. */
final readonly class Psr18Transport implements Transport
{
    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutMs): Response
    {
        $request = $this->requests->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            $request = $request->withBody($this->streams->createStream($body));
        }

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $exception) {
            throw new TransportException($exception->getMessage());
        }

        $received = [];

        foreach (array_keys($response->getHeaders()) as $name) {
            $received[(string) $name] = $response->getHeaderLine((string) $name);
        }

        return new Response($response->getStatusCode(), $received, (string) $response->getBody());
    }
}
