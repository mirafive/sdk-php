<?php

declare(strict_types=1);

function emit(): string
{
    $output = [];
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/contract/emit.php'), $output, $code);

    expect($code)->toBe(0);

    return implode("\n", $output);
}

it('prints the same batches on every run', function (): void {
    expect(emit())->toBe(emit());
});

it('prints every kind of event in valid batches', function (): void {
    $batches = json_decode(emit(), true, 512, JSON_THROW_ON_ERROR);
    $events = array_merge(...array_column($batches, 'events'));
    $names = array_unique(array_column($events, 'event'));

    expect($batches)->not->toBeEmpty()
        ->and(array_column($batches, 'seq'))->toBe(range(1, count($batches)))
        ->and($names)->toContain('$identify', '$group', '$revenue', '$touchpoint', 'report_exported', 'heartbeat', 'consent_variant')
        ->and(count($events))->toBe(count(array_unique(array_column($events, 'event_id'))))
        ->and(emit())->toContain('"properties":{}');
});
