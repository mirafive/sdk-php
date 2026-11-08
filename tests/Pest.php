<?php

declare(strict_types=1);

use MiraFive\Http\Response;
use MiraFive\Mira;
use MiraFive\MiraError;
use MiraFive\Testing\RecordingTransport;

/**
 * A client on a recording transport, with problems collected in `$errors`.
 *
 * @param  list<MiraError>  $errors
 * @param  array<string, mixed>  $options  more constructor arguments
 */
function client(RecordingTransport $transport, array &$errors = [], array $options = []): Mira
{
    return new Mira(...[
        'secretKey' => 'mira_sk_test',
        'host' => 'https://ingest.test',
        'onError' => function (MiraError $error) use (&$errors): void {
            $errors[] = $error;
        },
        'transport' => $transport,
        'flushOnShutdown' => false,
        'sleep' => static function (): void {},
        ...$options,
    ]);
}

/**
 * The events of every request, in order.
 *
 * @return list<array<string, mixed>>
 */
function sent(RecordingTransport $transport): array
{
    $events = [];

    foreach ($transport->requests() as $request) {
        foreach ($request['batch']['events'] ?? [] as $event) {
            $events[] = $event;
        }
    }

    return $events;
}

/**
 * @param  array<array-key, mixed>|null  $body
 * @param  array<string, string>  $headers
 */
function answer(int $status, ?array $body = null, array $headers = []): Response
{
    return new Response($status, $headers, $body === null ? '' : (string) json_encode($body));
}

/**
 * @param  list<MiraError>  $errors
 * @return list<string>
 */
function messages(array $errors): array
{
    return array_map(static fn (MiraError $error): string => $error->getMessage(), $errors);
}
