<?php

declare(strict_types=1);

use MiraFive\Http\CurlTransport;
use MiraFive\Http\StreamTransport;
use MiraFive\Http\Transport;
use MiraFive\Http\TransportException;

beforeAll(function (): void {
    $port = random_int(20000, 40000);
    $command = sprintf('exec %s -S 127.0.0.1:%d %s', escapeshellarg(PHP_BINARY), $port, escapeshellarg(dirname(__DIR__).'/Support/ingest.php'));
    $GLOBALS['ingest'] = proc_open($command, [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
    $GLOBALS['ingestUrl'] = "http://127.0.0.1:{$port}";

    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port);

        if ($socket !== false) {
            fclose($socket);

            return;
        }

        usleep(20_000);
    }
});

afterAll(function (): void {
    if (is_resource($GLOBALS['ingest'] ?? null)) {
        proc_terminate($GLOBALS['ingest']);
    }
});

dataset('transports', [
    'curl' => [fn (): Transport => new CurlTransport],
    'streams' => [fn (): Transport => new StreamTransport],
]);

it('posts a batch and reads the answer', function (Transport $transport): void {
    $response = $transport->request('POST', $GLOBALS['ingestUrl'].'/v1/batch', ['Authorization' => 'Bearer mira_sk_x', 'Content-Type' => 'application/json'], '{"events":[{},{}]}', 2000, 1000);

    expect($response->status)->toBe(202)
        ->and($response->json())->toMatchArray(['accepted' => 2, 'auth' => 'Bearer mira_sk_x', 'method' => 'POST', 'path' => '/v1/batch']);
})->with('transports');

it('returns error answers with their headers', function (Transport $transport): void {
    $response = $transport->request('POST', $GLOBALS['ingestUrl'].'/v1/batch', ['X-Answer' => '429'], '{}', 2000, 1000);

    expect($response->status)->toBe(429)
        ->and($response->header('Retry-After'))->toBe('10');
})->with('transports');

it('throws when nothing answers', function (Transport $transport): void {
    $transport->request('POST', 'http://127.0.0.1:1/v1/batch', [], '{}', 500, 500);
})->with('transports')->throws(TransportException::class);
