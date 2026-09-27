<?php

declare(strict_types=1);

use MiraFive\Http\Response;
use MiraFive\Tests\Support\FakeTransport;

/**
 * @return array<array-key, mixed>
 */
function loadFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/Fixtures/'.$name), true, flags: JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param  array<array-key, mixed>|null  $body
 * @param  array<string, string>  $headers
 */
function answer(int $status, ?array $body = null, array $headers = []): Response
{
    return new Response($status, $headers, $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR));
}

function accepted(int $count = 1): Response
{
    return answer(202, ['batch' => '00000000-0000-4000-8000-000000000000', 'accepted' => $count, 'dropped' => 0]);
}

function transport(Response|Throwable ...$answers): FakeTransport
{
    return new FakeTransport(...$answers);
}
